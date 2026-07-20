'use client';

import { Minus, Plus } from 'lucide-react';
import {
  type ChangeEvent,
  type KeyboardEvent,
  type MouseEvent,
  type PointerEvent,
  useCallback,
  useEffect,
  useRef,
  useState,
} from 'react';
import {
  commitSecondsStepperDraft,
  moveSecondsStepperValue,
  normalizeSecondsStepperValue,
} from './secondsStepperValue';
import styles from './SecondsStepper.module.css';

export const SECONDS_STEPPER_HOLD_DELAY_MS = 420;
export const SECONDS_STEPPER_REPEAT_INTERVAL_MS = 110;

export interface SecondsStepperProps {
  id: string;
  label: string;
  value: number;
  min: number;
  max: number;
  onChange: (value: number) => void;
  step?: number;
  disabled?: boolean;
  required?: boolean;
  name?: string;
  description?: string;
  className?: string;
  unit?: string;
  unitLabel?: string;
}

export function SecondsStepper({
  id,
  label,
  value,
  min,
  max,
  onChange,
  step = 1,
  disabled = false,
  required = false,
  name,
  description,
  className,
  unit = 'sec.',
  unitLabel = 'seconden',
}: SecondsStepperProps) {
  const normalizedValue = normalizeSecondsStepperValue(value, min, max, step);
  const maximumValue = normalizeSecondsStepperValue(max, min, max, step);
  const [editing, setEditing] = useState(false);
  const [draftValue, setDraftValue] = useState(() => String(normalizedValue));
  const actionableValue = editing
    ? commitSecondsStepperDraft(draftValue, normalizedValue, min, max, step)
    : normalizedValue;
  const descriptionId = description === undefined ? undefined : `${id}-description`;
  const constraintsId = `${id}-constraints`;
  const describedBy = descriptionId === undefined
    ? constraintsId
    : `${descriptionId} ${constraintsId}`;
  const holdTimeoutRef = useRef<number | null>(null);
  const repeatIntervalRef = useRef<number | null>(null);
  const ignoreNextPointerClickRef = useRef(false);
  const liveValueRef = useRef(actionableValue);
  const onChangeRef = useRef(onChange);
  const constraintsRef = useRef({ min, max, step, disabled });
  onChangeRef.current = onChange;
  constraintsRef.current = { min, max, step, disabled };

  const stopRepeating = useCallback((): void => {
    if (holdTimeoutRef.current !== null) {
      window.clearTimeout(holdTimeoutRef.current);
      holdTimeoutRef.current = null;
    }
    if (repeatIntervalRef.current !== null) {
      window.clearInterval(repeatIntervalRef.current);
      repeatIntervalRef.current = null;
    }
  }, []);

  useEffect(() => {
    if (!editing) setDraftValue(String(normalizedValue));
  }, [editing, normalizedValue]);

  useEffect(() => {
    if (holdTimeoutRef.current === null && repeatIntervalRef.current === null) {
      liveValueRef.current = actionableValue;
    }
  }, [actionableValue]);

  useEffect(() => {
    if (disabled) stopRepeating();
  }, [disabled, stopRepeating]);

  useEffect(() => {
    window.addEventListener('blur', stopRepeating);
    return () => {
      window.removeEventListener('blur', stopRepeating);
      stopRepeating();
    };
  }, [stopRepeating]);

  function emit(nextValue: number): void {
    if (disabled) return;
    const normalizedNextValue = normalizeSecondsStepperValue(nextValue, min, max, step);
    setDraftValue(String(normalizedNextValue));
    if (normalizedNextValue !== value) onChange(normalizedNextValue);
  }

  function handleInputChange(event: ChangeEvent<HTMLInputElement>): void {
    setDraftValue(event.currentTarget.value);
  }

  function commitDraft(): void {
    const committed = commitSecondsStepperDraft(draftValue, normalizedValue, min, max, step);
    setEditing(false);
    setDraftValue(String(committed));
    if (!disabled && committed !== value) onChange(committed);
  }

  function handleInputKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'Enter') event.currentTarget.blur();
  }

  function emitRepeatedStep(direction: -1 | 1): boolean {
    const constraints = constraintsRef.current;
    if (constraints.disabled) {
      stopRepeating();
      return false;
    }
    const nextValue = moveSecondsStepperValue(
      liveValueRef.current,
      direction,
      constraints.min,
      constraints.max,
      constraints.step,
    );
    if (nextValue === liveValueRef.current) {
      stopRepeating();
      return false;
    }

    liveValueRef.current = nextValue;
    setDraftValue(String(nextValue));
    onChangeRef.current(nextValue);
    return true;
  }

  function startRepeating(event: PointerEvent<HTMLButtonElement>, direction: -1 | 1): void {
    if (!event.isPrimary || event.button !== 0 || disabled) return;
    event.preventDefault();
    stopRepeating();
    ignoreNextPointerClickRef.current = true;
    liveValueRef.current = actionableValue;
    if (!emitRepeatedStep(direction)) return;

    holdTimeoutRef.current = window.setTimeout(() => {
      holdTimeoutRef.current = null;
      if (!emitRepeatedStep(direction)) return;
      repeatIntervalRef.current = window.setInterval(() => {
        emitRepeatedStep(direction);
      }, SECONDS_STEPPER_REPEAT_INTERVAL_MS);
    }, SECONDS_STEPPER_HOLD_DELAY_MS);
  }

  function handleStepClick(event: MouseEvent<HTMLButtonElement>, direction: -1 | 1): void {
    if (event.detail > 0 && ignoreNextPointerClickRef.current) {
      ignoreNextPointerClickRef.current = false;
      return;
    }
    emit(moveSecondsStepperValue(actionableValue, direction, min, max, step));
  }

  return (
    <div
      className={[styles.root, className].filter(Boolean).join(' ')}
      data-disabled={disabled ? 'true' : undefined}
    >
      <label className={styles.label} htmlFor={id}>{label}</label>
      {description === undefined ? null : (
        <span className={styles.description} id={descriptionId}>{description}</span>
      )}

      <div className={styles.control}>
        <button
          className={styles.stepButton}
          type="button"
          onPointerDown={(event) => startRepeating(event, -1)}
          onPointerUp={stopRepeating}
          onPointerCancel={stopRepeating}
          onPointerLeave={stopRepeating}
          onBlur={stopRepeating}
          onClick={(event) => handleStepClick(event, -1)}
          disabled={disabled || actionableValue <= min}
          aria-label={`${label} verlagen`}
        >
          <Minus size={18} strokeWidth={2.25} aria-hidden />
        </button>

        <span className={styles.valueShell}>
          <input
            className={styles.input}
            id={id}
            name={name}
            type="number"
            inputMode="numeric"
            autoComplete="off"
            min={min}
            max={max}
            step={step}
            value={editing ? draftValue : normalizedValue}
            disabled={disabled}
            required={required}
            aria-describedby={describedBy}
            onFocus={() => {
              setDraftValue(String(normalizedValue));
              setEditing(true);
            }}
            onChange={handleInputChange}
            onBlur={commitDraft}
            onKeyDown={handleInputKeyDown}
          />
          <span className={styles.unit} aria-hidden="true">{unit}</span>
        </span>

        <button
          className={styles.stepButton}
          type="button"
          onPointerDown={(event) => startRepeating(event, 1)}
          onPointerUp={stopRepeating}
          onPointerCancel={stopRepeating}
          onPointerLeave={stopRepeating}
          onBlur={stopRepeating}
          onClick={(event) => handleStepClick(event, 1)}
          disabled={disabled || actionableValue >= maximumValue}
          aria-label={`${label} verhogen`}
        >
          <Plus size={18} strokeWidth={2.25} aria-hidden />
        </button>
      </div>

      <span className={styles.srOnly} id={constraintsId}>
        Waarde in {unitLabel}. Minimum {min}, maximum {max}, stapgrootte {step}.
      </span>
    </div>
  );
}
