'use client';

import { Minus, Plus } from 'lucide-react';
import {
  type ChangeEvent,
  type KeyboardEvent,
  useEffect,
  useState,
} from 'react';
import {
  commitSecondsStepperDraft,
  moveSecondsStepperValue,
  normalizeSecondsStepperValue,
} from './secondsStepperValue';
import styles from './SecondsStepper.module.css';

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

  useEffect(() => {
    if (!editing) setDraftValue(String(normalizedValue));
  }, [editing, normalizedValue]);

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
          onPointerDown={(event) => event.preventDefault()}
          onClick={() => emit(moveSecondsStepperValue(
            actionableValue,
            -1,
            min,
            max,
            step,
          ))}
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
          <span className={styles.unit} aria-hidden="true">sec.</span>
        </span>

        <button
          className={styles.stepButton}
          type="button"
          onPointerDown={(event) => event.preventDefault()}
          onClick={() => emit(moveSecondsStepperValue(
            actionableValue,
            1,
            min,
            max,
            step,
          ))}
          disabled={disabled || actionableValue >= maximumValue}
          aria-label={`${label} verhogen`}
        >
          <Plus size={18} strokeWidth={2.25} aria-hidden />
        </button>
      </div>

      <span className={styles.srOnly} id={constraintsId}>
        Waarde in seconden. Minimum {min}, maximum {max}, stapgrootte {step}.
      </span>
    </div>
  );
}
