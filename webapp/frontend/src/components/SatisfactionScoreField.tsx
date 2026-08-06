'use client';

import {
  Annoyed,
  Frown,
  Laugh,
  Meh,
  Smile,
  type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { satisfactionScoreOptions } from '../lib/formFieldTypes';
import styles from './SatisfactionScoreField.module.css';

const scoreIcons: Record<number, LucideIcon> = {
  1: Frown,
  2: Annoyed,
  3: Meh,
  4: Smile,
  5: Laugh,
};

export interface SatisfactionScoreFieldProps {
  id: string;
  label: ReactNode;
  value: unknown;
  onChange: (value: number | null) => void;
  required?: boolean;
  disabled?: boolean;
  invalid?: boolean;
  helpText?: ReactNode;
  className?: string;
  compact?: boolean;
}

export function SatisfactionScoreField({
  id,
  label,
  value,
  onChange,
  required = false,
  disabled = false,
  invalid = false,
  helpText,
  className,
  compact = false,
}: SatisfactionScoreFieldProps) {
  const selectedValue = satisfactionScoreValue(value);
  const helpId = helpText === undefined || helpText === null ? undefined : `${id}-help`;
  const fieldClassName = [
    styles.field,
    compact ? styles.compact : '',
    className ?? '',
  ].filter(Boolean).join(' ');

  return (
    <fieldset
      id={id}
      className={fieldClassName}
      aria-describedby={helpId}
      aria-invalid={invalid || undefined}
    >
      <legend>{label}</legend>
      <div className={styles.scale}>
        {satisfactionScoreOptions.map((option) => {
          const Icon = scoreIcons[option.value];
          const selected = selectedValue === option.value;

          return (
            <label
              className={`${styles.option}${selected ? ` ${styles.optionSelected}` : ''}`}
              data-score={option.value}
              key={option.value}
            >
              <input
                className={styles.input}
                type="radio"
                name={id}
                value={option.value}
                checked={selected}
                required={required}
                disabled={disabled}
                aria-label={`${option.label}, score ${option.value} van 5`}
                onChange={() => onChange(option.value)}
              />
              <span className={styles.face} aria-hidden="true">
                <Icon strokeWidth={1.9} />
              </span>
              <span className={styles.label}>{option.label}</span>
              <span className={styles.index} aria-hidden="true">{option.value}</span>
            </label>
          );
        })}
      </div>
      <div className={styles.footer}>
        {helpText !== undefined && helpText !== null ? <small id={helpId}>{helpText}</small> : <span />}
        {!required && !disabled && selectedValue !== null ? (
          <button className={styles.clear} type="button" onClick={() => onChange(null)}>
            Score wissen
          </button>
        ) : null}
      </div>
    </fieldset>
  );
}

export function satisfactionScoreValue(value: unknown): number | null {
  const numeric = typeof value === 'number'
    ? value
    : typeof value === 'string' && value.trim() !== ''
      ? Number(value)
      : Number.NaN;

  return Number.isInteger(numeric) && numeric >= 1 && numeric <= 5 ? numeric : null;
}
