import { Form } from '@inertiajs/react';
import type { RouteFormDefinition } from '@/wayfinder';
import {
    CircleAlert,
    CircleCheck,
    KeyRound,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { destroy, update, verify } from '@/routes/credentials';
import type { CredentialProvider, CredentialSummary, Option } from '@/types';

const TOKEN_HELP: Record<CredentialProvider, ReactNode> = {
    github: (
        <>
            Classic token: <code>repo</code> scope (add <code>read:org</code> to
            create repos in organizations). Fine-grained token: Contents, Pull
            requests and Administration set to read &amp; write.
        </>
    ),
    gitlab: (
        <>
            Personal, group or project access token with the <code>api</code>{' '}
            scope.
        </>
    ),
    anthropic: (
        <>
            An API key from the Anthropic Console, billed per token. Runs Claude
            Code for every project that picks it.
        </>
    ),
    claude_subscription: (
        <>
            Your Pro, Max or Team plan instead of a paid API key. On your own
            computer run <code>claude setup-token</code>, sign in, and paste the
            token it prints (it starts with <code>sk-ant-oat</code> and lasts a
            year). It's only used for runs you start, never shared with your
            team.
        </>
    ),
    meta: (
        <>
            A Meta Model API key. Claude Code runs on Meta's Muse models through
            Meta's Anthropic-compatible endpoint.
        </>
    ),
    qwen: (
        <>
            An Alibaba Model Studio key. Claude Code runs on Qwen models through
            Model Studio's Anthropic-compatible endpoint. Pick the plan the key
            belongs to; a key only works with its own plan.
        </>
    ),
};

/** Providers whose checked account name is worth showing (who or which plan). */
const SHOWS_ACCOUNT: CredentialProvider[] = ['github', 'gitlab', 'qwen'];

type Props = {
    credentials: CredentialSummary[];
    providers: Option<CredentialProvider>[];
    qwenEndpoints: Option[];
    storeForm: RouteFormDefinition<'post'>;
    canManage: boolean;
    emptyText: string;
};

export default function CredentialsManager({
    credentials,
    providers,
    qwenEndpoints,
    storeForm,
    canManage,
    emptyText,
}: Props) {
    return (
        <div className="space-y-4">
            {canManage ? (
                <AddCredentialDialog
                    providers={providers}
                    qwenEndpoints={qwenEndpoints}
                    storeForm={storeForm}
                >
                    <Button data-test="add-token-button">
                        <Plus /> Add token
                    </Button>
                </AddCredentialDialog>
            ) : null}

            <div className="space-y-3">
                {credentials.map((credential) => (
                    <CredentialRow
                        key={credential.id}
                        credential={credential}
                        qwenEndpoints={qwenEndpoints}
                        canManage={canManage}
                    />
                ))}

                {credentials.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <KeyRound className="mx-auto mb-2 size-5" />
                        {emptyText}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function CredentialRow({
    credential,
    qwenEndpoints,
    canManage,
}: {
    credential: CredentialSummary;
    qwenEndpoints: Option[];
    canManage: boolean;
}) {
    const verified = credential.last_verified_at !== null;

    return (
        <div
            data-test="credential-row"
            className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4"
        >
            <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{credential.name}</span>
                    <Badge variant="secondary">
                        {credential.provider_label}
                    </Badge>
                    <span className="font-mono text-xs text-muted-foreground">
                        {credential.token_hint}
                    </span>
                </div>

                {verified ? (
                    <p className="flex items-center gap-1.5 text-sm text-green-700 dark:text-green-400">
                        <CircleCheck className="size-4 shrink-0" />
                        Works
                        {credential.account_name &&
                        SHOWS_ACCOUNT.includes(credential.provider)
                            ? ` · ${credential.account_name}`
                            : ''}
                        {credential.base_url && credential.provider === 'gitlab'
                            ? ` · ${credential.base_url}`
                            : ''}
                    </p>
                ) : (
                    <p className="flex items-start gap-1.5 text-sm text-red-700 dark:text-red-400">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>
                            {credential.last_error ?? 'Not checked yet'}
                        </span>
                    </p>
                )}

                {credential.scopes && credential.scopes.length > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        Scopes: {credential.scopes.join(', ')}
                    </p>
                ) : null}

                {credential.projects_count ? (
                    <p className="text-xs text-muted-foreground">
                        Used by {credential.projects_count}{' '}
                        {credential.projects_count === 1
                            ? 'project'
                            : 'projects'}
                    </p>
                ) : null}
            </div>

            {canManage ? (
                <div className="flex items-center gap-1">
                    <Form
                        {...verify.form(credential.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                disabled={processing}
                                aria-label={`Check ${credential.name}`}
                                title="Check again"
                            >
                                <RefreshCw
                                    className={
                                        processing
                                            ? 'size-4 animate-spin'
                                            : 'size-4'
                                    }
                                />
                            </Button>
                        )}
                    </Form>

                    <EditCredentialDialog
                        credential={credential}
                        qwenEndpoints={qwenEndpoints}
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`Edit ${credential.name}`}
                            title="Rename or replace token"
                        >
                            <Pencil className="size-4" />
                        </Button>
                    </EditCredentialDialog>

                    <DeleteCredentialDialog credential={credential}>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`Delete ${credential.name}`}
                            title="Delete"
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </DeleteCredentialDialog>
                </div>
            ) : null}
        </div>
    );
}

function AddCredentialDialog({
    providers,
    qwenEndpoints,
    storeForm,
    children,
}: {
    providers: Option<CredentialProvider>[];
    qwenEndpoints: Option[];
    storeForm: RouteFormDefinition<'post'>;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [provider, setProvider] = useState<CredentialProvider>('github');

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                setProvider('github');
            }}
        >
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...storeForm}
                    className="space-y-6"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Add a token</DialogTitle>
                                <DialogDescription>
                                    Tokens are encrypted when saved and are
                                    never shown again after this.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="provider">Provider</Label>
                                <Select
                                    name="provider"
                                    value={provider}
                                    onValueChange={(value) =>
                                        setProvider(value as CredentialProvider)
                                    }
                                >
                                    <SelectTrigger id="provider">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {providers.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.provider} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    placeholder={
                                        provider === 'github' ||
                                        provider === 'gitlab'
                                            ? 'Deploy bot'
                                            : provider === 'claude_subscription'
                                              ? 'My Max plan'
                                              : 'Production key'
                                    }
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="token">Token</Label>
                                <Input
                                    id="token"
                                    name="token"
                                    type="password"
                                    required
                                    autoComplete="off"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {TOKEN_HELP[provider]}
                                </p>
                                <InputError message={errors.token} />
                            </div>

                            {provider === 'gitlab' ? (
                                <BaseUrlField error={errors.base_url} />
                            ) : null}

                            {provider === 'qwen' ? (
                                <QwenPlanField
                                    options={qwenEndpoints}
                                    error={errors.base_url}
                                />
                            ) : null}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="save-token-button"
                                >
                                    {processing
                                        ? 'Checking…'
                                        : 'Save and check'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditCredentialDialog({
    credential,
    qwenEndpoints,
    children,
}: {
    credential: CredentialSummary;
    qwenEndpoints: Option[];
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...update.form(credential.id)}
                    className="space-y-6"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Edit {credential.name}
                                </DialogTitle>
                                <DialogDescription>
                                    Leave the token empty to keep the current
                                    one ({credential.token_hint}).
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={credential.name}
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="token">New token</Label>
                                <Input
                                    id="token"
                                    name="token"
                                    type="password"
                                    autoComplete="off"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {TOKEN_HELP[credential.provider]}
                                </p>
                                <InputError message={errors.token} />
                            </div>

                            {credential.provider === 'gitlab' ? (
                                <BaseUrlField
                                    error={errors.base_url}
                                    defaultValue={credential.base_url ?? ''}
                                />
                            ) : null}

                            {credential.provider === 'qwen' ? (
                                <QwenPlanField
                                    options={qwenEndpoints}
                                    error={errors.base_url}
                                    defaultValue={credential.base_url ?? ''}
                                />
                            ) : null}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteCredentialDialog({
    credential,
    children,
}: {
    credential: CredentialSummary;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    {...destroy.form(credential.id)}
                    className="space-y-6"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Delete {credential.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    {credential.projects_count
                                        ? `${credential.projects_count} ${credential.projects_count === 1 ? 'project uses' : 'projects use'} this token and will need a new one before agents can run.`
                                        : 'No projects use this token.'}{' '}
                                    This only removes it from Software Factory;
                                    revoke it with {credential.provider_label}{' '}
                                    too if you no longer need it.
                                </DialogDescription>
                            </DialogHeader>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Delete token
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function BaseUrlField({
    error,
    defaultValue = '',
}: {
    error?: string;
    defaultValue?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor="base_url">GitLab URL (optional)</Label>
            <Input
                id="base_url"
                name="base_url"
                type="url"
                placeholder="https://gitlab.com"
                defaultValue={defaultValue}
                autoComplete="off"
            />
            <p className="text-xs text-muted-foreground">
                Only for self-hosted GitLab. Leave empty for gitlab.com.
            </p>
            <InputError message={error} />
        </div>
    );
}

function QwenPlanField({
    options,
    error,
    defaultValue,
}: {
    options: Option[];
    error?: string;
    defaultValue?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor="base_url">Plan</Label>
            <Select
                name="base_url"
                defaultValue={defaultValue || options[0]?.value}
            >
                <SelectTrigger id="base_url">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}
