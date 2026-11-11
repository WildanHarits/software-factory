import InputError from '@/components/input-error';
import LabeledSelect from '@/components/projects/labeled-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Option } from '@/types';

export type TicketFormOptions = {
    models: Option[];
    efforts: Option[];
    approvalModes: Option[];
};

type Values = {
    title?: string;
    description?: string | null;
    model: string;
    effort: string;
    approval_mode: string;
    skip_clarify?: boolean;
    skip_analyze?: boolean;
};

/** Fields shared by "New feature" and the ticket's settings. */
export default function TicketFormFields({
    options,
    values,
    errors,
    titleLocked = false,
}: {
    options: TicketFormOptions;
    values: Values;
    errors: Record<string, string | undefined>;
    titleLocked?: boolean;
}) {
    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <Label htmlFor="title">Feature</Label>
                <Input
                    id="title"
                    name="title"
                    defaultValue={values.title}
                    placeholder="e.g. Photo albums"
                    readOnly={titleLocked}
                    required
                    autoComplete="off"
                />
                {titleLocked ? (
                    <p className="text-xs text-muted-foreground">
                        Fixed after Specify, because it names the branch.
                    </p>
                ) : null}
                <InputError message={errors.title} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">What should it do, and why?</Label>
                <Textarea
                    id="description"
                    name="description"
                    rows={5}
                    defaultValue={values.description ?? ''}
                    placeholder="Describe the feature for the people who'll use it. The agent turns this into a spec. Leave out the tech stack; that comes in Plan."
                />
                <InputError message={errors.description} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <LabeledSelect
                    id="model"
                    label="Model"
                    options={options.models}
                    defaultValue={values.model}
                    error={errors.model}
                />
                <LabeledSelect
                    id="effort"
                    label="Effort"
                    options={options.efforts}
                    defaultValue={values.effort}
                    error={errors.effort}
                />
            </div>

            <LabeledSelect
                id="approval_mode"
                label="Between phases"
                options={options.approvalModes}
                defaultValue={values.approval_mode}
                help="Auto mode still stops after Clarify, since the agent's questions need a person."
                error={errors.approval_mode}
            />

            <div className="grid gap-3 sm:grid-cols-2">
                <CheckboxField
                    name="skip_clarify"
                    label="Skip Clarify"
                    defaultChecked={values.skip_clarify}
                />
                <CheckboxField
                    name="skip_analyze"
                    label="Skip Analyze"
                    defaultChecked={values.skip_analyze}
                />
            </div>
        </div>
    );
}

function CheckboxField({
    name,
    label,
    defaultChecked,
}: {
    name: string;
    label: string;
    defaultChecked?: boolean;
}) {
    return (
        <div className="flex items-center gap-2">
            <Checkbox
                id={name}
                name={name}
                value="1"
                defaultChecked={defaultChecked}
            />
            <Label htmlFor={name} className="font-normal">
                {label}
            </Label>
        </div>
    );
}
