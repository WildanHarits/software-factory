import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Option } from '@/types';

type Props = {
    id: string;
    label: string;
    options: Option[];
    defaultValue?: string;
    value?: string;
    onValueChange?: (value: string) => void;
    placeholder?: string;
    help?: ReactNode;
    error?: string;
    disabled?: boolean;
};

/** A labelled select whose value is submitted with the surrounding form. */
export default function LabeledSelect({
    id,
    label,
    options,
    defaultValue,
    value,
    onValueChange,
    placeholder,
    help,
    error,
    disabled,
}: Props) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select
                name={id}
                defaultValue={defaultValue}
                value={value}
                onValueChange={onValueChange}
                disabled={disabled}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {help ? (
                <p className="text-xs text-muted-foreground">{help}</p>
            ) : null}
            <InputError message={error} />
        </div>
    );
}
