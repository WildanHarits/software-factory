import { Form } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import LabeledSelect from '@/components/projects/labeled-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Option } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    form: RouteFormDefinition<'post'>;
    title: string;
    description: ReactNode;
    field: {
        name: string;
        label: string;
        placeholder: string;
        help?: ReactNode;
        required?: boolean;
    };
    submitLabel: string;
    models: Option[];
    efforts: Option[];
    defaultModel: string;
    defaultEffort: string;
    children: ReactNode;
};

/** Asks for the run's input and model/effort, then starts it. */
export default function StartRunDialog({
    form,
    title,
    description,
    field,
    submitLabel,
    models,
    efforts,
    defaultModel,
    defaultEffort,
    children,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <Form key={String(open)} {...form} className="space-y-6">
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{title}</DialogTitle>
                                <DialogDescription>
                                    {description}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor={field.name}>
                                    {field.label}
                                </Label>
                                <Textarea
                                    id={field.name}
                                    name={field.name}
                                    rows={6}
                                    placeholder={field.placeholder}
                                    required={field.required}
                                />
                                {field.help ? (
                                    <p className="text-xs text-muted-foreground">
                                        {field.help}
                                    </p>
                                ) : null}
                                <InputError message={errors[field.name]} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <LabeledSelect
                                    id="model"
                                    label="Model"
                                    options={models}
                                    defaultValue={defaultModel}
                                    error={errors.model}
                                />
                                <LabeledSelect
                                    id="effort"
                                    label="Effort"
                                    options={efforts}
                                    defaultValue={defaultEffort}
                                    error={errors.effort}
                                />
                            </div>

                            <InputError message={errors.run} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing ? (
                                        <LoaderCircle className="animate-spin" />
                                    ) : null}
                                    {submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
