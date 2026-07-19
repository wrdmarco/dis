import { Fragment, type ReactNode } from 'react';
import type {
  WallboardRichTextBlock,
  WallboardRichTextDocument,
  WallboardRichTextMark,
  WallboardRichTextRun,
} from '../../types/api';

export const EMPTY_WALLBOARD_RICH_TEXT: WallboardRichTextDocument = {
  version: 1,
  blocks: [{ type: 'paragraph', align: 'left', runs: [{ text: '' }] }],
};

const MAX_BLOCKS = 24;
const MAX_LIST_ITEMS = 12;
const MAX_RUNS = 160;
const MAX_RUN_LENGTH = 500;
const MAX_VISIBLE_CHARACTERS = 2000;
const ALLOWED_MARKS = new Set<WallboardRichTextMark>(['bold', 'italic']);

export function WallboardRichText({
  content,
  className,
  ariaLabel,
}: {
  content: WallboardRichTextDocument;
  className?: string;
  ariaLabel?: string;
}) {
  const normalized = normalizeWallboardRichText(content);

  return (
    <div className={className} aria-label={ariaLabel}>
      {normalized.blocks.map((block, index) => (
        <Fragment key={`${block.type}-${index}`}>
          {renderBlock(block, index)}
        </Fragment>
      ))}
    </div>
  );
}

export function normalizeWallboardRichText(
  value: unknown,
  legacyBody = '',
): WallboardRichTextDocument {
  if (!isRecord(value) || value.version !== 1 || !Array.isArray(value.blocks)) {
    return wallboardRichTextFromPlainText(legacyBody);
  }

  let runCount = 0;
  let characterCount = 0;
  const blocks = value.blocks.slice(0, MAX_BLOCKS).flatMap((candidate): WallboardRichTextBlock[] => {
    if (!isRecord(candidate) || typeof candidate.type !== 'string') return [];

    if (candidate.type === 'bullet_list' || candidate.type === 'numbered_list') {
      if (!Array.isArray(candidate.items)) return [];
      const items = candidate.items.slice(0, MAX_LIST_ITEMS).flatMap((item) => {
        if (!isRecord(item) || !Array.isArray(item.runs)) return [];
        const runs = normalizeRuns(item.runs, () => runCount, (value) => { runCount = value; }, () => characterCount, (value) => { characterCount = value; });
        return runs.length === 0 ? [] : [{ runs }];
      });
      return items.length === 0 ? [] : [{ type: candidate.type, items }];
    }

    if (!['heading', 'paragraph', 'quote'].includes(candidate.type) || !Array.isArray(candidate.runs)) return [];
    const runs = normalizeRuns(candidate.runs, () => runCount, (value) => { runCount = value; }, () => characterCount, (value) => { characterCount = value; });
    if (runs.length === 0) return [];
    return [{
      type: candidate.type as 'heading' | 'paragraph' | 'quote',
      align: candidate.align === 'center' ? 'center' : 'left',
      runs,
    }];
  });

  return blocks.length === 0 ? wallboardRichTextFromPlainText(legacyBody) : { version: 1, blocks };
}

export function wallboardRichTextFromPlainText(value: string): WallboardRichTextDocument {
  const text = value.replace(/\r\n?/g, '\n').slice(0, MAX_VISIBLE_CHARACTERS);
  const paragraphs = text.split(/\n{2,}/).slice(0, MAX_BLOCKS);
  const blocks: WallboardRichTextBlock[] = paragraphs
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph !== '')
    .map((paragraph) => ({
      type: 'paragraph',
      align: 'left',
      runs: chunkRunText(paragraph, []),
    }));

  return {
    version: 1,
    blocks: blocks.length > 0 ? blocks : EMPTY_WALLBOARD_RICH_TEXT.blocks.map(copyBlock),
  };
}

export function wallboardRichTextCharacterCount(content: WallboardRichTextDocument): number {
  return content.blocks.reduce((total, block) => total + (
    block.type === 'bullet_list' || block.type === 'numbered_list'
      ? block.items.reduce((itemsTotal, item) => itemsTotal + runTextLength(item.runs), 0)
      : runTextLength(block.runs)
  ), 0);
}

export function wallboardRichTextIsEmpty(content: WallboardRichTextDocument): boolean {
  return wallboardRichTextCharacterCount(content) === 0;
}

function renderBlock(block: WallboardRichTextBlock, index: number): ReactNode {
  if (block.type === 'bullet_list' || block.type === 'numbered_list') {
    const List = block.type === 'bullet_list' ? 'ul' : 'ol';
    return (
      <List className={`wallboard-rich-text__${block.type}`}>
        {block.items.map((item, itemIndex) => (
          <li key={`${index}-${itemIndex}`}>{renderRuns(item.runs)}</li>
        ))}
      </List>
    );
  }

  const style = { textAlign: block.align } as const;
  if (block.type === 'heading') return <h2 style={style}>{renderRuns(block.runs)}</h2>;
  if (block.type === 'quote') return <blockquote style={style}>{renderRuns(block.runs)}</blockquote>;
  return <p style={style}>{renderRuns(block.runs)}</p>;
}

function renderRuns(runs: WallboardRichTextRun[]): ReactNode {
  return runs.map((run, index) => {
    let content: ReactNode = run.text;
    if (run.marks?.includes('italic')) content = <em>{content}</em>;
    if (run.marks?.includes('bold')) content = <strong>{content}</strong>;
    return <Fragment key={`${index}-${run.text.length}`}>{content}</Fragment>;
  });
}

function normalizeRuns(
  candidates: unknown[],
  getRunCount: () => number,
  setRunCount: (value: number) => void,
  getCharacterCount: () => number,
  setCharacterCount: (value: number) => void,
): WallboardRichTextRun[] {
  const runs: WallboardRichTextRun[] = [];
  for (const candidate of candidates) {
    if (getRunCount() >= MAX_RUNS || getCharacterCount() >= MAX_VISIBLE_CHARACTERS || !isRecord(candidate)) break;
    const remaining = MAX_VISIBLE_CHARACTERS - getCharacterCount();
    const text = typeof candidate.text === 'string'
      ? candidate.text.slice(0, Math.min(MAX_RUN_LENGTH, remaining))
      : '';
    if (text === '') continue;
    const marks = Array.isArray(candidate.marks)
      ? [...new Set(candidate.marks.filter((mark): mark is WallboardRichTextMark => (
        typeof mark === 'string' && ALLOWED_MARKS.has(mark as WallboardRichTextMark)
      )))].sort()
      : [];
    for (const chunk of chunkRunText(text, marks)) {
      const previous = runs.at(-1);
      if (previous && sameMarks(previous.marks, marks) && previous.text.length + chunk.text.length <= MAX_RUN_LENGTH) {
        previous.text += chunk.text;
      } else {
        runs.push(chunk);
        setRunCount(getRunCount() + 1);
      }
    }
    setCharacterCount(getCharacterCount() + text.length);
  }
  return runs;
}

function chunkRunText(text: string, marks: WallboardRichTextMark[]): WallboardRichTextRun[] {
  const chunks: WallboardRichTextRun[] = [];
  for (let offset = 0; offset < text.length; offset += MAX_RUN_LENGTH) {
    const chunk = text.slice(offset, offset + MAX_RUN_LENGTH);
    chunks.push(marks.length > 0 ? { text: chunk, marks: [...marks] } : { text: chunk });
  }
  return chunks;
}

function sameMarks(left: WallboardRichTextMark[] | undefined, right: WallboardRichTextMark[]): boolean {
  const normalizedLeft = left ?? [];
  return normalizedLeft.length === right.length && normalizedLeft.every((mark, index) => mark === right[index]);
}

function runTextLength(runs: WallboardRichTextRun[]): number {
  return runs.reduce((total, run) => total + run.text.length, 0);
}

function copyBlock(block: WallboardRichTextBlock): WallboardRichTextBlock {
  if (block.type === 'bullet_list' || block.type === 'numbered_list') {
    return { ...block, items: block.items.map((item) => ({ runs: item.runs.map((run) => ({ ...run, marks: run.marks ? [...run.marks] : undefined })) })) };
  }
  return { ...block, runs: block.runs.map((run) => ({ ...run, marks: run.marks ? [...run.marks] : undefined })) };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
