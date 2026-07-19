import {
  AlignCenter,
  AlignLeft,
  Bold,
  Italic,
  List,
  ListOrdered,
  Plus,
  Quote,
  Text,
  Trash2,
  Type,
} from 'lucide-react';
import {
  useCallback,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ClipboardEvent,
  type CompositionEvent,
  type FormEvent,
  type KeyboardEvent,
  type MouseEvent,
} from 'react';
import type {
  WallboardRichTextBlock,
  WallboardRichTextDocument,
  WallboardRichTextMark,
  WallboardRichTextRun,
} from '../../types/api';
import {
  normalizeWallboardRichText,
  wallboardRichTextCharacterCount,
} from './WallboardRichText';

interface RichTarget {
  blockIndex: number;
  itemIndex: number | null;
}

interface PendingSelection extends RichTarget {
  offset: number;
}

const CARET_SENTINEL = '\u200B';

export function WallboardRichTextEditor({
  value,
  onChange,
  id,
}: {
  value: WallboardRichTextDocument;
  onChange: (value: WallboardRichTextDocument) => void;
  id: string;
}) {
  const documentValue = useMemo(() => normalizeWallboardRichText(value), [value]);
  const [activeTarget, setActiveTarget] = useState<RichTarget>({ blockIndex: 0, itemIndex: null });
  const targetElements = useRef(new Map<string, HTMLDivElement>());
  const composingTargets = useRef(new Set<string>());
  const pendingSelection = useRef<PendingSelection | null>(null);
  const activeBlock = documentValue.blocks[activeTarget.blockIndex] ?? documentValue.blocks[0];

  const registerTarget = useCallback((target: RichTarget, element: HTMLDivElement | null) => {
    const key = targetKey(target);
    if (element === null) targetElements.current.delete(key);
    else targetElements.current.set(key, element);
  }, []);

  useLayoutEffect(() => {
    const pending = pendingSelection.current;
    if (pending === null) return;
    const element = targetElements.current.get(targetKey(pending));
    if (element === undefined) return;
    restoreCaret(element, pending.offset);
    pendingSelection.current = null;
  }, [documentValue]);

  function updateDocument(next: WallboardRichTextDocument, selection?: PendingSelection) {
    pendingSelection.current = selection ?? null;
    onChange(next);
  }

  function updateTargetRuns(
    target: RichTarget,
    runs: WallboardRichTextRun[],
    selectionOffset?: number,
    source = documentValue,
  ) {
    updateDocument(
      replaceTargetRuns(source, target, runs),
      selectionOffset === undefined ? undefined : { ...target, offset: selectionOffset },
    );
  }

  function handleInput(target: RichTarget, event: FormEvent<HTMLDivElement>) {
    const nativeEvent = event.nativeEvent as InputEvent;
    if (nativeEvent.isComposing || composingTargets.current.has(targetKey(target))) return;
    const root = event.currentTarget;
    updateTargetRuns(target, runsFromEditable(root));
  }

  function handleCompositionStart(target: RichTarget) {
    composingTargets.current.add(targetKey(target));
  }

  function handleCompositionEnd(target: RichTarget, event: CompositionEvent<HTMLDivElement>) {
    composingTargets.current.delete(targetKey(target));
    updateTargetRuns(target, runsFromEditable(event.currentTarget));
  }

  function handlePaste(target: RichTarget, event: ClipboardEvent<HTMLDivElement>) {
    event.preventDefault();
    const text = event.clipboardData.getData('text/plain').replace(/\r\n?/g, '\n');
    const root = event.currentTarget;
    replaceEditableSelection(root, text);
    updateTargetRuns(target, runsFromEditable(root));
  }

  function handleKeyDown(target: RichTarget, event: KeyboardEvent<HTMLDivElement>) {
    if (event.key !== 'Enter' || event.nativeEvent.isComposing || composingTargets.current.has(targetKey(target))) return;
    event.preventDefault();
    const root = event.currentTarget;
    replaceEditableSelection(root, '\n');
    updateTargetRuns(target, runsFromEditable(root));
  }

  function handleBeforeInput(target: RichTarget, event: FormEvent<HTMLDivElement>) {
    const nativeEvent = event.nativeEvent as InputEvent;
    if (
      nativeEvent.isComposing
      || composingTargets.current.has(targetKey(target))
      || (nativeEvent.inputType !== 'insertParagraph' && nativeEvent.inputType !== 'insertLineBreak')
    ) return;
    event.preventDefault();
    const root = event.currentTarget;
    replaceEditableSelection(root, '\n');
    updateTargetRuns(target, runsFromEditable(root));
  }

  function liveDocumentFor(target: RichTarget): WallboardRichTextDocument {
    const root = targetElements.current.get(targetKey(target));
    return root === undefined ? documentValue : replaceTargetRuns(documentValue, target, runsFromEditable(root));
  }

  function toggleMark(mark: WallboardRichTextMark) {
    const root = targetElements.current.get(targetKey(activeTarget));
    const source = liveDocumentFor(activeTarget);
    const runs = targetRuns(source, activeTarget);
    if (root === undefined || runs.length === 0) return;
    const selected = selectionOffsets(root);
    const start = selected && selected.start !== selected.end ? selected.start : 0;
    const end = selected && selected.start !== selected.end ? selected.end : targetTextLength(source, activeTarget);
    const nextRuns = toggleRunMark(runs, start, end, mark);
    writeRunsToEditable(root, nextRuns);
    restoreCaret(root, end);
    updateTargetRuns(activeTarget, nextRuns, end, source);
  }

  function changeBlockType(type: WallboardRichTextBlock['type']) {
    const source = liveDocumentFor(activeTarget);
    const root = targetElements.current.get(targetKey(activeTarget));
    const caretOffset = root === undefined ? 0 : (selectionOffsets(root)?.end ?? root.textContent?.length ?? 0);
    const blocks = source.blocks.map((block, index): WallboardRichTextBlock => {
      if (index !== activeTarget.blockIndex) return block;
      if (type === 'bullet_list' || type === 'numbered_list') {
        const items = block.type === 'bullet_list' || block.type === 'numbered_list'
          ? block.items
          : [{ runs: block.runs }];
        return { type, items };
      }
      const runs = block.type === 'bullet_list' || block.type === 'numbered_list'
        ? joinListRuns(block)
        : block.runs;
      return { type, align: 'left', runs };
    });
    const target = { blockIndex: activeTarget.blockIndex, itemIndex: type === 'bullet_list' || type === 'numbered_list' ? 0 : null };
    setActiveTarget(target);
    updateDocument({ version: 1, blocks }, { ...target, offset: caretOffset });
  }

  function changeAlignment(align: 'left' | 'center') {
    const source = liveDocumentFor(activeTarget);
    const blocks = source.blocks.map((block, index): WallboardRichTextBlock => (
      index === activeTarget.blockIndex && block.type !== 'bullet_list' && block.type !== 'numbered_list'
        ? { ...block, align }
        : block
    ));
    updateDocument({ version: 1, blocks });
  }

  function addBlock() {
    const source = liveDocumentFor(activeTarget);
    const insertAt = activeTarget.blockIndex + 1;
    const blocks = [...source.blocks];
    blocks.splice(insertAt, 0, { type: 'paragraph', align: 'left', runs: [{ text: '' }] });
    const target = { blockIndex: insertAt, itemIndex: null };
    setActiveTarget(target);
    updateDocument({ version: 1, blocks }, { ...target, offset: 0 });
  }

  function removeBlock() {
    const source = liveDocumentFor(activeTarget);
    if (source.blocks.length <= 1) return;
    const blocks = source.blocks.filter((_, index) => index !== activeTarget.blockIndex);
    const nextIndex = Math.max(0, Math.min(activeTarget.blockIndex, blocks.length - 1));
    const nextBlock = blocks[nextIndex];
    const target = {
      blockIndex: nextIndex,
      itemIndex: nextBlock.type === 'bullet_list' || nextBlock.type === 'numbered_list' ? 0 : null,
    };
    setActiveTarget(target);
    updateDocument({ version: 1, blocks }, { ...target, offset: 0 });
  }

  const blockType = activeBlock?.type ?? 'paragraph';
  const activeAlignment = activeBlock && activeBlock.type !== 'bullet_list' && activeBlock.type !== 'numbered_list'
    ? activeBlock.align
    : 'left';

  return (
    <div className="wallboard-rich-editor" id={id}>
      <div className="wallboard-rich-editor__toolbar" role="toolbar" aria-label="Opmaak voor mededeling">
        <button type="button" className={blockType === 'heading' ? 'is-active' : ''} onMouseDown={retainSelection} onClick={() => changeBlockType('heading')} aria-label="Kop"><Type size={17} aria-hidden /></button>
        <button type="button" className={blockType === 'paragraph' ? 'is-active' : ''} onMouseDown={retainSelection} onClick={() => changeBlockType('paragraph')} aria-label="Normale tekst"><Text size={17} aria-hidden /></button>
        <button type="button" className={blockType === 'quote' ? 'is-active' : ''} onMouseDown={retainSelection} onClick={() => changeBlockType('quote')} aria-label="Citaat"><Quote size={17} aria-hidden /></button>
        <span aria-hidden />
        <button type="button" onMouseDown={retainSelection} onClick={() => toggleMark('bold')} aria-label="Vet"><Bold size={17} aria-hidden /></button>
        <button type="button" onMouseDown={retainSelection} onClick={() => toggleMark('italic')} aria-label="Cursief"><Italic size={17} aria-hidden /></button>
        <span aria-hidden />
        <button type="button" className={blockType === 'bullet_list' ? 'is-active' : ''} onMouseDown={retainSelection} onClick={() => changeBlockType('bullet_list')} aria-label="Opsomming"><List size={17} aria-hidden /></button>
        <button type="button" className={blockType === 'numbered_list' ? 'is-active' : ''} onMouseDown={retainSelection} onClick={() => changeBlockType('numbered_list')} aria-label="Genummerde lijst"><ListOrdered size={17} aria-hidden /></button>
        <span aria-hidden />
        <button type="button" className={activeAlignment === 'left' ? 'is-active' : ''} disabled={blockType === 'bullet_list' || blockType === 'numbered_list'} onMouseDown={retainSelection} onClick={() => changeAlignment('left')} aria-label="Links uitlijnen"><AlignLeft size={17} aria-hidden /></button>
        <button type="button" className={activeAlignment === 'center' ? 'is-active' : ''} disabled={blockType === 'bullet_list' || blockType === 'numbered_list'} onMouseDown={retainSelection} onClick={() => changeAlignment('center')} aria-label="Centreren"><AlignCenter size={17} aria-hidden /></button>
        <span className="wallboard-rich-editor__toolbar-spacer" aria-hidden />
        <span className="wallboard-rich-editor__block-actions">
          <button type="button" onMouseDown={retainSelection} onClick={addBlock} aria-label="Tekstblok toevoegen"><Plus size={17} aria-hidden /></button>
          <button type="button" onMouseDown={retainSelection} onClick={removeBlock} disabled={documentValue.blocks.length <= 1} aria-label="Tekstblok verwijderen"><Trash2 size={17} aria-hidden /></button>
        </span>
      </div>

      <div className="wallboard-rich-editor__canvas" aria-label="Visuele mededeling-editor">
        {documentValue.blocks.map((block, blockIndex) => (
          <RichBlockEditor
            key={`block-${blockIndex}`}
            block={block}
            blockIndex={blockIndex}
            activeTarget={activeTarget}
            setActiveTarget={setActiveTarget}
            registerTarget={registerTarget}
            onInput={handleInput}
            onBeforeInput={handleBeforeInput}
            onCompositionStart={handleCompositionStart}
            onCompositionEnd={handleCompositionEnd}
            onPaste={handlePaste}
            onKeyDown={handleKeyDown}
          />
        ))}
      </div>
      <small>{wallboardRichTextCharacterCount(documentValue)}/2000 tekens · opmaak wordt als veilige tekststructuur opgeslagen</small>
    </div>
  );
}

function RichBlockEditor({
  block,
  blockIndex,
  activeTarget,
  setActiveTarget,
  registerTarget,
  onInput,
  onBeforeInput,
  onCompositionStart,
  onCompositionEnd,
  onPaste,
  onKeyDown,
}: {
  block: WallboardRichTextBlock;
  blockIndex: number;
  activeTarget: RichTarget;
  setActiveTarget: (target: RichTarget) => void;
  registerTarget: (target: RichTarget, element: HTMLDivElement | null) => void;
  onInput: (target: RichTarget, event: FormEvent<HTMLDivElement>) => void;
  onBeforeInput: (target: RichTarget, event: FormEvent<HTMLDivElement>) => void;
  onCompositionStart: (target: RichTarget) => void;
  onCompositionEnd: (target: RichTarget, event: CompositionEvent<HTMLDivElement>) => void;
  onPaste: (target: RichTarget, event: ClipboardEvent<HTMLDivElement>) => void;
  onKeyDown: (target: RichTarget, event: KeyboardEvent<HTMLDivElement>) => void;
}) {
  if (block.type === 'bullet_list' || block.type === 'numbered_list') {
    const ListElement = block.type === 'bullet_list' ? 'ul' : 'ol';
    return (
      <ListElement className={`wallboard-rich-editor__list wallboard-rich-editor__list--${block.type}`}>
        {block.items.map((item, itemIndex) => {
          const target = { blockIndex, itemIndex };
          return (
            <li key={`${blockIndex}-${itemIndex}`}>
              <EditableRuns
                target={target}
                runs={item.runs}
                active={sameTarget(activeTarget, target)}
                setActiveTarget={setActiveTarget}
                registerTarget={registerTarget}
                onInput={onInput}
                onBeforeInput={onBeforeInput}
                onCompositionStart={onCompositionStart}
                onCompositionEnd={onCompositionEnd}
                onPaste={onPaste}
                onKeyDown={onKeyDown}
              />
            </li>
          );
        })}
      </ListElement>
    );
  }

  const target = { blockIndex, itemIndex: null };
  return (
    <div className={`wallboard-rich-editor__block wallboard-rich-editor__block--${block.type}`} style={{ textAlign: block.align }}>
      <EditableRuns
        target={target}
        runs={block.runs}
        active={sameTarget(activeTarget, target)}
        setActiveTarget={setActiveTarget}
        registerTarget={registerTarget}
        onInput={onInput}
        onBeforeInput={onBeforeInput}
        onCompositionStart={onCompositionStart}
        onCompositionEnd={onCompositionEnd}
        onPaste={onPaste}
        onKeyDown={onKeyDown}
      />
    </div>
  );
}

function EditableRuns({
  target,
  runs,
  active,
  setActiveTarget,
  registerTarget,
  onInput,
  onBeforeInput,
  onCompositionStart,
  onCompositionEnd,
  onPaste,
  onKeyDown,
}: {
  target: RichTarget;
  runs: WallboardRichTextRun[];
  active: boolean;
  setActiveTarget: (target: RichTarget) => void;
  registerTarget: (target: RichTarget, element: HTMLDivElement | null) => void;
  onInput: (target: RichTarget, event: FormEvent<HTMLDivElement>) => void;
  onBeforeInput: (target: RichTarget, event: FormEvent<HTMLDivElement>) => void;
  onCompositionStart: (target: RichTarget) => void;
  onCompositionEnd: (target: RichTarget, event: CompositionEvent<HTMLDivElement>) => void;
  onPaste: (target: RichTarget, event: ClipboardEvent<HTMLDivElement>) => void;
  onKeyDown: (target: RichTarget, event: KeyboardEvent<HTMLDivElement>) => void;
}) {
  const rootRef = useRef<HTMLDivElement | null>(null);
  const composing = useRef(false);
  const blockIndex = target.blockIndex;
  const itemIndex = target.itemIndex;

  const setRoot = useCallback((element: HTMLDivElement | null) => {
    rootRef.current = element;
    registerTarget({ blockIndex, itemIndex }, element);
  }, [blockIndex, itemIndex, registerTarget]);

  useLayoutEffect(() => {
    const root = rootRef.current;
    if (root === null || composing.current) return;
    const focused = document.activeElement === root;
    const currentRuns = runsFromEditable(root);
    if (sameRuns(currentRuns, runs) || focused) return;
    writeRunsToEditable(root, runs);
  }, [runs]);

  return (
    <div
      ref={setRoot}
      className={active ? 'wallboard-rich-editor__editable is-active' : 'wallboard-rich-editor__editable'}
      contentEditable
      suppressContentEditableWarning
      role="textbox"
      aria-multiline="true"
      data-placeholder="Schrijf hier de mededeling…"
      onFocus={() => setActiveTarget(target)}
      onInput={(event) => onInput(target, event)}
      onBeforeInput={(event) => onBeforeInput(target, event)}
      onCompositionStart={() => {
        composing.current = true;
        onCompositionStart(target);
      }}
      onCompositionEnd={(event) => {
        composing.current = false;
        onCompositionEnd(target, event);
      }}
      onPaste={(event) => onPaste(target, event)}
      onDrop={(event) => event.preventDefault()}
      onKeyDown={(event) => onKeyDown(target, event)}
    />
  );
}

function replaceTargetRuns(
  documentValue: WallboardRichTextDocument,
  target: RichTarget,
  runs: WallboardRichTextRun[],
): WallboardRichTextDocument {
  const blocks = documentValue.blocks.map((block, blockIndex): WallboardRichTextBlock => {
    if (blockIndex !== target.blockIndex) return block;
    if (block.type === 'bullet_list' || block.type === 'numbered_list') {
      if (target.itemIndex === null) return block;
      return {
        ...block,
        items: block.items.map((item, itemIndex) => itemIndex === target.itemIndex ? { runs: ensureRuns(runs) } : item),
      };
    }
    return { ...block, runs: ensureRuns(runs) };
  });
  return { version: 1, blocks };
}

function targetRuns(documentValue: WallboardRichTextDocument, target: RichTarget): WallboardRichTextRun[] {
  const block = documentValue.blocks[target.blockIndex];
  if (block.type === 'bullet_list' || block.type === 'numbered_list') {
    return target.itemIndex === null ? [] : (block.items[target.itemIndex]?.runs ?? []);
  }
  return block.runs;
}

function targetTextLength(documentValue: WallboardRichTextDocument, target: RichTarget): number {
  return targetRuns(documentValue, target).reduce((total, run) => total + run.text.length, 0);
}

function toggleRunMark(
  runs: WallboardRichTextRun[],
  start: number,
  end: number,
  mark: WallboardRichTextMark,
): WallboardRichTextRun[] {
  const selectedRuns = sliceRuns(runs, start, end);
  const remove = selectedRuns.length > 0 && selectedRuns.every((run) => run.marks?.includes(mark));
  let offset = 0;
  const result: WallboardRichTextRun[] = [];
  for (const run of runs) {
    const runStart = offset;
    const runEnd = offset + run.text.length;
    offset = runEnd;
    if (runEnd <= start || runStart >= end) {
      result.push(copyRun(run));
      continue;
    }
    const beforeLength = Math.max(0, start - runStart);
    const selectedEnd = Math.min(run.text.length, end - runStart);
    if (beforeLength > 0) result.push({ ...copyRun(run), text: run.text.slice(0, beforeLength) });
    const selectedText = run.text.slice(beforeLength, selectedEnd);
    const marks = new Set(run.marks ?? []);
    if (remove) marks.delete(mark);
    else marks.add(mark);
    result.push({ text: selectedText, ...(marks.size > 0 ? { marks: [...marks].sort() as WallboardRichTextMark[] } : {}) });
    if (selectedEnd < run.text.length) result.push({ ...copyRun(run), text: run.text.slice(selectedEnd) });
  }
  return mergeRuns(result);
}

function sliceRuns(runs: WallboardRichTextRun[], start: number, end: number): WallboardRichTextRun[] {
  let offset = 0;
  const result: WallboardRichTextRun[] = [];
  for (const run of runs) {
    const runStart = offset;
    const runEnd = offset + run.text.length;
    offset = runEnd;
    const sliceStart = Math.max(start, runStart);
    const sliceEnd = Math.min(end, runEnd);
    if (sliceStart >= sliceEnd) continue;
    result.push({ ...copyRun(run), text: run.text.slice(sliceStart - runStart, sliceEnd - runStart) });
  }
  return mergeRuns(result);
}

function runsFromEditable(root: HTMLDivElement): WallboardRichTextRun[] {
  const runs: WallboardRichTextRun[] = [];
  const append = (text: string, marks: WallboardRichTextMark[]) => {
    if (text === '') return;
    runs.push(marks.length > 0 ? { text, marks: [...marks] } : { text });
  };
  const visit = (node: Node, inheritedMarks: WallboardRichTextMark[]) => {
    if (node.nodeType === Node.TEXT_NODE) {
      append((node.textContent ?? '').replaceAll(CARET_SENTINEL, ''), inheritedMarks);
      return;
    }
    if (!(node instanceof HTMLElement)) return;
    const ownMarks = node.dataset.richMarks === undefined
      ? inheritedMarks
      : node.dataset.richMarks
        .split(',')
        .filter((mark): mark is WallboardRichTextMark => mark === 'bold' || mark === 'italic');
    if (node.tagName === 'BR') {
      append('\n', ownMarks);
      return;
    }
    for (const child of node.childNodes) visit(child, ownMarks);
  };
  for (const child of root.childNodes) visit(child, []);
  return ensureRuns(mergeRuns(runs));
}

function writeRunsToEditable(root: HTMLDivElement, runs: WallboardRichTextRun[]) {
  const fragment = document.createDocumentFragment();
  for (const run of runs) {
    if (run.text === '') continue;
    const marks = run.marks ?? [];
    const parts = run.text.split('\n');
    parts.forEach((part, index) => {
      if (part !== '') {
        const span = document.createElement('span');
        span.dataset.richMarks = marks.join(',');
        span.className = marks.map((mark) => `is-${mark}`).join(' ');
        span.textContent = part;
        fragment.append(span);
      }
      if (index < parts.length - 1) {
        const lineBreak = document.createElement('br');
        lineBreak.dataset.richMarks = marks.join(',');
        fragment.append(lineBreak);
      }
    });
  }
  if (runs.at(-1)?.text.endsWith('\n')) fragment.append(document.createTextNode(CARET_SENTINEL));
  root.replaceChildren(fragment);
}

function replaceEditableSelection(root: HTMLDivElement, text: string) {
  const selection = window.getSelection();
  if (selection === null) return;

  let range: Range;
  if (
    selection.rangeCount > 0
    && selection.anchorNode !== null
    && selection.focusNode !== null
    && root.contains(selection.anchorNode)
    && root.contains(selection.focusNode)
  ) {
    range = selection.getRangeAt(0);
  } else {
    range = document.createRange();
    range.selectNodeContents(root);
    range.collapse(false);
  }

  range.deleteContents();
  const fragment = document.createDocumentFragment();
  const parts = text.split('\n');
  let caretNode: Node | null = null;
  parts.forEach((part, index) => {
    if (part !== '') {
      caretNode = document.createTextNode(part);
      fragment.append(caretNode);
    }
    if (index < parts.length - 1) {
      const lineBreak = document.createElement('br');
      fragment.append(lineBreak);
      caretNode = lineBreak;
    }
  });
  if (text.endsWith('\n')) {
    caretNode = document.createTextNode(CARET_SENTINEL);
    fragment.append(caretNode);
  }
  range.insertNode(fragment);
  if (caretNode?.nodeType === Node.TEXT_NODE) range.setStart(caretNode, caretNode.textContent?.length ?? 0);
  else if (caretNode !== null) range.setStartAfter(caretNode);
  range.collapse(true);
  selection.removeAllRanges();
  selection.addRange(range);
}

function sameRuns(left: WallboardRichTextRun[], right: WallboardRichTextRun[]): boolean {
  if (left.length !== right.length) return false;
  return left.every((run, index) => (
    run.text === right[index]?.text
    && sameMarks(run.marks, [...(right[index]?.marks ?? [])].sort() as WallboardRichTextMark[])
  ));
}

function selectionOffsets(root: HTMLElement): { start: number; end: number } | null {
  const selection = window.getSelection();
  if (selection === null || selection.rangeCount === 0 || selection.anchorNode === null || selection.focusNode === null) return null;
  if (!root.contains(selection.anchorNode) || !root.contains(selection.focusNode)) return null;
  const range = selection.getRangeAt(0);
  const beforeStart = document.createRange();
  beforeStart.selectNodeContents(root);
  beforeStart.setEnd(range.startContainer, range.startOffset);
  const beforeEnd = document.createRange();
  beforeEnd.selectNodeContents(root);
  beforeEnd.setEnd(range.endContainer, range.endOffset);
  const first = editablePlainText(beforeStart.cloneContents()).length;
  const second = editablePlainText(beforeEnd.cloneContents()).length;
  return { start: Math.min(first, second), end: Math.max(first, second) };
}

function restoreCaret(root: HTMLElement, offset: number) {
  const selection = window.getSelection();
  if (selection === null) return;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
  let remaining = Math.max(0, offset);
  let node = walker.nextNode();
  while (node !== null) {
    if (node.nodeType === Node.TEXT_NODE) {
      const length = node.textContent?.length ?? 0;
      if (remaining <= length) {
        const range = document.createRange();
        range.setStart(node, remaining);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        root.focus();
        return;
      }
      remaining -= length;
    } else if (node instanceof HTMLBRElement) {
      const range = document.createRange();
      if (remaining === 0) range.setStartBefore(node);
      else if (remaining === 1) range.setStartAfter(node);
      else {
        remaining -= 1;
        node = walker.nextNode();
        continue;
      }
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
      root.focus();
      return;
    }
    node = walker.nextNode();
  }
  root.focus();
}

function editablePlainText(root: Node): string {
  let text = '';
  const visit = (node: Node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      text += (node.textContent ?? '').replaceAll(CARET_SENTINEL, '');
      return;
    }
    if (node instanceof HTMLBRElement) {
      text += '\n';
      return;
    }
    for (const child of node.childNodes) visit(child);
  };
  visit(root);
  return text;
}

function joinListRuns(block: Extract<WallboardRichTextBlock, { type: 'bullet_list' | 'numbered_list' }>): WallboardRichTextRun[] {
  return mergeRuns(block.items.flatMap((item, index) => index === 0 ? item.runs : [{ text: '\n' }, ...item.runs]));
}

function mergeRuns(runs: WallboardRichTextRun[]): WallboardRichTextRun[] {
  const merged: WallboardRichTextRun[] = [];
  for (const run of runs) {
    if (run.text === '') continue;
    const marks = [...new Set(run.marks ?? [])].sort() as WallboardRichTextMark[];
    let remaining = run.text;
    const previous = merged.at(-1);
    if (previous && sameMarks(previous.marks, marks) && previous.text.length < 500) {
      const available = 500 - previous.text.length;
      previous.text += remaining.slice(0, available);
      remaining = remaining.slice(available);
    }
    while (remaining !== '') {
      const text = remaining.slice(0, 500);
      merged.push(marks.length > 0 ? { text, marks: [...marks] } : { text });
      remaining = remaining.slice(500);
    }
  }
  return merged;
}

function ensureRuns(runs: WallboardRichTextRun[]): WallboardRichTextRun[] {
  return runs.length > 0 ? runs : [{ text: '' }];
}

function copyRun(run: WallboardRichTextRun): WallboardRichTextRun {
  return run.marks ? { text: run.text, marks: [...run.marks] } : { text: run.text };
}

function sameMarks(left: WallboardRichTextMark[] | undefined, right: WallboardRichTextMark[]): boolean {
  const normalizedLeft = left ?? [];
  return normalizedLeft.length === right.length && normalizedLeft.every((mark, index) => mark === right[index]);
}

function sameTarget(left: RichTarget, right: RichTarget): boolean {
  return left.blockIndex === right.blockIndex && left.itemIndex === right.itemIndex;
}

function targetKey(target: RichTarget): string {
  return `${target.blockIndex}:${target.itemIndex ?? 'block'}`;
}

function retainSelection(event: MouseEvent<HTMLButtonElement>) {
  event.preventDefault();
}
