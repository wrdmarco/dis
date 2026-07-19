import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  normalizeWallboardRichText,
  wallboardRichTextCharacterCount,
} from '../src/features/wallboards/WallboardRichText';

test('an empty block created with plus remains in the controlled document', () => {
  const content = normalizeWallboardRichText({
    version: 1,
    blocks: [
      { type: 'paragraph', align: 'left', runs: [{ text: 'Eerste blok' }] },
      { type: 'paragraph', align: 'left', runs: [{ text: '' }] },
    ],
  });

  expect(content.blocks).toHaveLength(2);
  expect(content.blocks[1]).toEqual({
    type: 'paragraph',
    align: 'left',
    runs: [{ text: '' }],
  });
});

test('a newline remains inside one safe text block', () => {
  const content = normalizeWallboardRichText({
    version: 1,
    blocks: [{ type: 'paragraph', align: 'left', runs: [{ text: 'Regel een\nRegel twee' }] }],
  });

  expect(content.blocks).toHaveLength(1);
  expect(content.blocks[0]).toMatchObject({ runs: [{ text: 'Regel een\nRegel twee' }] });
  expect(wallboardRichTextCharacterCount(content)).toBe(20);
});

test.describe('contenteditable DOM regressions', () => {
  test.beforeEach(async ({ page }) => {
    await page.setContent(`
      <style>.editable { white-space: pre-wrap; }</style>
      <button id="quote" type="button">Citaat</button>
      <button id="add" type="button">Tekstblok toevoegen</button>
      <div id="canvas">
        <div class="block" data-type="paragraph">
          <div class="editable" contenteditable="true" role="textbox" aria-multiline="true">Eerste</div>
        </div>
      </div>
      <script>
        (() => {
          const canvas = document.querySelector('#canvas');
          const quote = document.querySelector('#quote');
          const add = document.querySelector('#add');
          let composing = false;
          let active = canvas.querySelector('.editable');
          let changeCount = 0;
          const model = { version: 1, blocks: [{ type: 'paragraph', align: 'left', runs: [{ text: 'Eerste' }] }] };

          const placeCaretAtEnd = (root) => {
            const range = document.createRange();
            range.selectNodeContents(root);
            range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            root.focus();
          };

          const replaceSelection = (root, text) => {
            const selection = window.getSelection();
            let range;
            if (selection.rangeCount > 0 && root.contains(selection.anchorNode) && root.contains(selection.focusNode)) {
              range = selection.getRangeAt(0);
            } else {
              range = document.createRange();
              range.selectNodeContents(root);
              range.collapse(false);
            }
            range.deleteContents();
            const fragment = document.createDocumentFragment();
            const parts = text.split('\\n');
            let caretNode = null;
            parts.forEach((part, index) => {
              if (part !== '') {
                caretNode = document.createTextNode(part);
                fragment.append(caretNode);
              }
              if (index < parts.length - 1) {
                caretNode = document.createElement('br');
                fragment.append(caretNode);
              }
            });
            if (text.endsWith('\\n')) {
              caretNode = document.createTextNode(String.fromCharCode(0x200b));
              fragment.append(caretNode);
            }
            range.insertNode(fragment);
            if (caretNode.nodeType === Node.TEXT_NODE) range.setStart(caretNode, caretNode.textContent.length);
            else range.setStartAfter(caretNode);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
          };

          const plainText = (root) => {
            let text = '';
            const visit = (node) => {
              if (node.nodeType === Node.TEXT_NODE) text += (node.textContent ?? '').replaceAll(String.fromCharCode(0x200b), '');
              else if (node instanceof HTMLBRElement) text += '\\n';
              else node.childNodes.forEach(visit);
            };
            visit(root);
            return text;
          };

          const commit = (root) => {
            const index = [...canvas.querySelectorAll('.editable')].indexOf(root);
            model.blocks[index].runs = [{ text: plainText(root) }];
            changeCount += 1;
          };

          const bind = (editable) => {
            editable.addEventListener('focus', () => { active = editable; });
            editable.addEventListener('compositionstart', () => { composing = true; });
            editable.addEventListener('compositionend', () => { composing = false; commit(editable); });
            editable.addEventListener('input', (event) => {
              if (!composing && !event.isComposing) commit(editable);
            });
            editable.addEventListener('keydown', (event) => {
              if (event.key !== 'Enter' || event.isComposing || composing) return;
              event.preventDefault();
              replaceSelection(editable, '\\n');
              commit(editable);
            });
            editable.addEventListener('paste', (event) => {
              event.preventDefault();
              replaceSelection(editable, event.clipboardData.getData('text/plain').replace(/\\r\\n?/g, '\\n'));
              commit(editable);
            });
          };

          bind(active);
          quote.addEventListener('mousedown', (event) => event.preventDefault());
          quote.addEventListener('click', () => {
            const index = [...canvas.querySelectorAll('.editable')].indexOf(active);
            commit(active);
            model.blocks[index].type = 'quote';
            active.closest('.block').dataset.type = 'quote';
            placeCaretAtEnd(active);
          });
          add.addEventListener('mousedown', (event) => event.preventDefault());
          add.addEventListener('click', () => {
            const block = document.createElement('div');
            block.className = 'block';
            block.dataset.type = 'paragraph';
            const editable = document.createElement('div');
            editable.className = 'editable';
            editable.contentEditable = 'true';
            editable.setAttribute('role', 'textbox');
            editable.setAttribute('aria-multiline', 'true');
            block.append(editable);
            active.closest('.block').after(block);
            const index = [...canvas.querySelectorAll('.editable')].indexOf(editable);
            model.blocks.splice(index, 0, { type: 'paragraph', align: 'left', runs: [{ text: '' }] });
            bind(editable);
            placeCaretAtEnd(editable);
          });

          window.editorHarness = { model, get active() { return active; }, get changeCount() { return changeCount; }, placeCaretAtEnd };
          placeCaretAtEnd(active);
        })();
      </script>
    `);
  });

  test('Enter creates a line in the current block and plus creates a separate focused block', async ({ page }) => {
    const editable = page.locator('.editable').first();
    await editable.press('End');
    await editable.press('Enter');
    await editable.pressSequentially('Tweede');

    expect(await page.evaluate(() => (window as unknown as {
      editorHarness: { model: { blocks: Array<{ runs: Array<{ text: string }> }> } };
    }).editorHarness.model.blocks.map((block) => block.runs[0]?.text))).toEqual(['Eerste\nTweede']);

    await page.getByRole('button', { name: 'Tekstblok toevoegen' }).click();
    await page.locator('.editable').nth(1).pressSequentially('Apart');

    const result = await page.evaluate(() => {
      const harness = (window as unknown as {
        editorHarness: { active: HTMLElement; model: { blocks: Array<{ runs: Array<{ text: string }> }> } };
      }).editorHarness;
      return {
        activeIndex: [...document.querySelectorAll('.editable')].indexOf(harness.active),
        texts: harness.model.blocks.map((block) => block.runs[0]?.text),
      };
    });
    expect(result).toEqual({ activeIndex: 1, texts: ['Eerste\nTweede', 'Apart'] });
  });

  test('quote conversion keeps composed text once and future Android-style input is not duplicated', async ({ page }) => {
    await page.evaluate(() => {
      const editable = document.querySelector<HTMLElement>('.editable')!;
      editable.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true, data: '' }));
      editable.textContent = 'Alarm';
      editable.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        data: 'Alarm',
        inputType: 'insertCompositionText',
        isComposing: true,
      }));
      editable.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: 'Alarm' }));
    });

    const editableHandle = await page.locator('.editable').first().evaluate((element) => element.dataset.testIdentity = 'stable');
    expect(editableHandle).toBe('stable');
    await page.getByRole('button', { name: 'Citaat' }).click();
    await page.locator('.editable').first().pressSequentially(' abc');

    const result = await page.evaluate(() => {
      const harness = (window as unknown as {
        editorHarness: { model: { blocks: Array<{ type: string; runs: Array<{ text: string }> }> } };
      }).editorHarness;
      const editable = document.querySelector<HTMLElement>('.editable')!;
      return {
        identity: editable.dataset.testIdentity,
        type: harness.model.blocks[0]?.type,
        text: harness.model.blocks[0]?.runs[0]?.text,
      };
    });
    expect(result).toEqual({ identity: 'stable', type: 'quote', text: 'Alarm abc' });
  });

  test('paste inserts only text/plain', async ({ page }) => {
    await page.evaluate(() => {
      const editable = document.querySelector<HTMLElement>('.editable')!;
      const harness = (window as unknown as { editorHarness: { placeCaretAtEnd: (root: HTMLElement) => void } }).editorHarness;
      harness.placeCaretAtEnd(editable);
      const transfer = new DataTransfer();
      transfer.setData('text/plain', '<strong> veilig</strong>');
      transfer.setData('text/html', '<img src=x onerror=alert(1)>');
      editable.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: transfer }));
    });

    await expect(page.locator('.editable').first()).toHaveText('Eerste<strong> veilig</strong>');
    await expect(page.locator('.editable img')).toHaveCount(0);
  });
});

test('the production editor owns active contenteditable DOM and guards IME composition', () => {
  const editor = readFileSync(new URL('../src/features/wallboards/WallboardRichTextEditor.tsx', import.meta.url), 'utf8');
  const renderer = readFileSync(new URL('../src/features/wallboards/WallboardRichText.tsx', import.meta.url), 'utf8');
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(editor).toContain('composingTargets.current.has(targetKey(target))');
  expect(editor).toContain('nativeEvent.isComposing');
  expect(editor).toContain('root.replaceChildren(fragment)');
  expect(editor).not.toContain('dangerouslySetInnerHTML');
  expect(editor).toContain("replaceEditableSelection(root, '\\n')");
  expect(editor).toContain('key={`block-${blockIndex}`}');
  expect(editor).toContain('aria-multiline="true"');
  expect(editor).toContain('wallboard-rich-editor__block-actions');
  expect(renderer).toContain("whiteSpace: 'pre-wrap'");
  expect(styles).toContain('.wallboard-rich-editor__block-actions');
  expect(styles).toContain('position: sticky');
});
