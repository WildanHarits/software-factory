import { Form, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CircleAlert,
    ExternalLink,
    GitBranch,
    RotateCcw,
    Send,
    Square,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { TicketStatusLine } from '@/components/board/ticket-card';
import TicketFormFields from '@/components/board/ticket-form-fields';
import type { TicketFormOptions } from '@/components/board/ticket-form-fields';
import InputError from '@/components/input-error';
import Markdown from '@/components/markdown';
import RunStatusBadge, { formatCost } from '@/components/runs/run-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { show as showRun } from '@/routes/projects/runs';
import {
    cancel,
    destroy,
    move,
    reply,
    retry,
    update,
} from '@/routes/projects/tickets';
import type { TicketDetail } from '@/types';

type Props = {
    ticket: TicketDetail | null;
    open: boolean;
    onClose: () => void;
    routeArgs: { current_team: string; project: string };
    canWork: boolean;
    repoWebUrl: string;
    options: TicketFormOptions;
};

export default function TicketPanel({
    ticket,
    open,
    onClose,
    routeArgs,
    canWork,
    repoWebUrl,
    options,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={(value) => !value && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-2xl">
                {ticket ? (
                    <PanelBody
                        ticket={ticket}
                        routeArgs={routeArgs}
                        canWork={canWork}
                        repoWebUrl={repoWebUrl}
                        options={options}
                    />
                ) : (
                    <SheetHeader>
                        <SheetTitle>Loading…</SheetTitle>
                        <SheetDescription />
                    </SheetHeader>
                )}
            </SheetContent>
        </Sheet>
    );
}

function PanelBody({
    ticket,
    routeArgs,
    canWork,
    repoWebUrl,
    options,
}: Omit<Props, 'open' | 'onClose' | 'ticket'> & { ticket: TicketDetail }) {
    const args = { ...routeArgs, ticket: ticket.number };
    const lastRun = ticket.last_run;

    return (
        <div className="flex flex-col gap-6 px-4 pb-8">
            <SheetHeader className="px-0">
                <SheetDescription className="flex items-center gap-2">
                    #{ticket.number} · {ticket.stage_label}
                </SheetDescription>
                <SheetTitle className="pr-6 text-xl">{ticket.title}</SheetTitle>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <TicketStatusLine ticket={ticket} />
                    {ticket.branch ? (
                        <a
                            href={`${repoWebUrl}/tree/${ticket.branch}`}
                            target="_blank"
                            rel="noreferrer"
                            className="flex items-center gap-1 hover:text-foreground"
                        >
                            <GitBranch className="size-3.5" />
                            {ticket.branch}
                            <ExternalLink className="size-3" />
                        </a>
                    ) : null}
                    <span>
                        {ticket.model_label} · {ticket.effort}
                    </span>
                    {ticket.cost_usd > 0 ? (
                        <span>{formatCost(ticket.cost_usd)} so far</span>
                    ) : null}
                </div>
            </SheetHeader>

            {lastRun &&
            (lastRun.status === 'failed' || lastRun.status === 'cancelled') &&
            ticket.status === 'failed' ? (
                <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" />
                    <div className="min-w-0 space-y-1">
                        <p className="break-words">
                            {lastRun.status === 'cancelled'
                                ? 'The last run was cancelled.'
                                : (lastRun.error ?? 'The last run failed.')}
                        </p>
                        <Link
                            href={showRun({ ...routeArgs, run: lastRun.id })}
                            className="underline underline-offset-4"
                        >
                            Open the run log
                        </Link>
                    </div>
                </div>
            ) : null}

            {canWork ? (
                <Actions ticket={ticket} args={args} routeArgs={routeArgs} />
            ) : null}

            {ticket.last_message && ticket.status !== 'running' ? (
                <Section
                    title={
                        ticket.stage === 'clarify'
                            ? "The agent's questions"
                            : ticket.stage === 'analyze'
                              ? 'Analysis report'
                              : "The agent's summary"
                    }
                >
                    <div className="rounded-lg border bg-muted/30 p-3">
                        <Markdown>{ticket.last_message}</Markdown>
                    </div>
                </Section>
            ) : null}

            {canWork && ticket.can_reply && ticket.status !== 'running' ? (
                <ReplyForm ticket={ticket} args={args} />
            ) : null}

            <Documents ticket={ticket} />

            {ticket.description ? (
                <Section title="Description">
                    <Markdown>{ticket.description}</Markdown>
                </Section>
            ) : null}

            <Section title="Runs">
                {ticket.runs.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing has run yet.
                    </p>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {ticket.runs.map((run) => (
                            <Link
                                key={run.id}
                                href={showRun({ ...routeArgs, run: run.id })}
                                className="flex items-center justify-between gap-3 p-2.5 text-sm hover:bg-accent/50"
                            >
                                <span className="flex items-center gap-2">
                                    <RunStatusBadge
                                        status={run.status}
                                        label={run.status_label}
                                    />
                                    {run.type_label}
                                    {run.is_reply ? (
                                        <span className="text-xs text-muted-foreground">
                                            reply
                                        </span>
                                    ) : null}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatCost(run.cost_usd)}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}
            </Section>

            {canWork ? (
                <Settings ticket={ticket} args={args} options={options} />
            ) : null}
        </div>
    );
}

function Actions({
    ticket,
    args,
    routeArgs,
}: {
    ticket: TicketDetail;
    args: { current_team: string; project: string; ticket: number };
    routeArgs: { current_team: string; project: string };
}) {
    if (ticket.status === 'running') {
        return (
            <div className="flex flex-wrap items-center gap-2">
                {ticket.last_run ? (
                    <Button asChild>
                        <Link
                            href={showRun({
                                ...routeArgs,
                                run: ticket.last_run.id,
                            })}
                        >
                            Watch the live log <ArrowRight />
                        </Link>
                    </Button>
                ) : null}
                <Form {...cancel.form(args)} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            <Square /> Cancel run
                        </Button>
                    )}
                </Form>
            </div>
        );
    }

    if (ticket.stage === 'backlog') {
        return (
            <MoveForm
                args={args}
                stage="specify"
                label="Start Specify"
                notesLabel="Extra context for the spec (optional)"
            />
        );
    }

    if (ticket.stage === 'review') {
        return <MoveForm args={args} stage="done" label="Move to Done" />;
    }

    if (ticket.stage === 'done') {
        return null;
    }

    return (
        <div className="space-y-3">
            {ticket.status === 'awaiting_approval' && ticket.next_stage ? (
                <MoveForm
                    args={args}
                    stage={ticket.next_stage}
                    label={
                        ticket.next_stage === 'review'
                            ? 'Approve and move to Review'
                            : ticket.stage === 'clarify'
                              ? `Done answering, start ${ticket.next_stage_label}`
                              : `Approve and start ${ticket.next_stage_label}`
                    }
                    notesLabel={
                        ticket.next_stage === 'review'
                            ? undefined
                            : `Guidance for ${ticket.next_stage_label} (optional)`
                    }
                    notesPlaceholder={
                        ticket.next_stage === 'plan'
                            ? 'e.g. Laravel + React, store photos on S3, no new dependencies'
                            : undefined
                    }
                />
            ) : null}

            {ticket.status === 'failed' ? (
                <Form {...retry.form(args)} options={{ preserveScroll: true }}>
                    {({ processing, errors }) => (
                        <>
                            <Button type="submit" disabled={processing}>
                                <RotateCcw /> Run {ticket.stage_label} again
                            </Button>
                            <InputError
                                className="mt-2"
                                message={errors.ticket}
                            />
                        </>
                    )}
                </Form>
            ) : null}
        </div>
    );
}

function MoveForm({
    args,
    stage,
    label,
    notesLabel,
    notesPlaceholder,
}: {
    args: { current_team: string; project: string; ticket: number };
    stage: string;
    label: string;
    notesLabel?: string;
    notesPlaceholder?: string;
}) {
    return (
        <Form
            {...move.form(args)}
            className="space-y-3 rounded-lg border p-3"
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="stage" value={stage} />
                    {notesLabel ? (
                        <div className="grid gap-2">
                            <Label htmlFor="notes">{notesLabel}</Label>
                            <Textarea
                                id="notes"
                                name="notes"
                                rows={2}
                                placeholder={notesPlaceholder}
                            />
                        </div>
                    ) : null}
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="move-ticket-submit"
                    >
                        {label} <ArrowRight />
                    </Button>
                    <InputError message={errors.ticket ?? errors.stage} />
                </>
            )}
        </Form>
    );
}

function ReplyForm({
    ticket,
    args,
}: {
    ticket: TicketDetail;
    args: { current_team: string; project: string; ticket: number };
}) {
    const answering = ticket.stage === 'clarify';

    return (
        <Section title={answering ? 'Your answers' : 'Ask for changes'}>
            <Form
                {...reply.form(args)}
                className="space-y-3"
                options={{ preserveScroll: true }}
                resetOnSuccess
            >
                {({ processing, errors }) => (
                    <>
                        <Textarea
                            name="message"
                            rows={4}
                            required
                            placeholder={
                                answering
                                    ? 'Answer the questions above. The agent continues the same conversation and updates the spec.'
                                    : `Tell the agent what to change in this ${ticket.stage_label.toLowerCase()} step. It continues the same conversation.`
                            }
                        />
                        <InputError message={errors.message ?? errors.ticket} />
                        <Button
                            type="submit"
                            variant={answering ? 'default' : 'outline'}
                            disabled={processing}
                        >
                            <Send /> Send to the agent
                        </Button>
                    </>
                )}
            </Form>
        </Section>
    );
}

function Documents({ ticket }: { ticket: TicketDetail }) {
    const [selected, setSelected] = useState<string | null>(null);
    const documents = ticket.documents;

    if (documents.length === 0) {
        return null;
    }

    // Main Spec Kit files first.
    const order = [
        'spec.md',
        'plan.md',
        'tasks.md',
        'research.md',
        'data-model.md',
        'quickstart.md',
    ];
    const sorted = [...documents].sort((a, b) => {
        const ai = order.indexOf(a.name);
        const bi = order.indexOf(b.name);

        return (
            (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi) ||
            a.path.localeCompare(b.path)
        );
    });
    const current =
        sorted.find((document) => document.path === selected) ?? sorted[0];

    return (
        <Section title="Documents">
            <div className="flex flex-wrap gap-1" role="tablist">
                {sorted.map((document) => (
                    <button
                        key={document.path}
                        type="button"
                        role="tab"
                        aria-selected={document.path === current.path}
                        onClick={() => setSelected(document.path)}
                        title={document.path}
                        className={cn(
                            'rounded-md border px-2 py-1 text-xs',
                            document.path === current.path
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'hover:bg-accent',
                        )}
                    >
                        {document.path.replace(`${ticket.feature_dir}/`, '')}
                    </button>
                ))}
            </div>
            <div className="rounded-lg border p-4" data-test="ticket-document">
                {current.name.endsWith('.md') ? (
                    <Markdown>{current.content}</Markdown>
                ) : (
                    <pre className="overflow-x-auto text-xs whitespace-pre-wrap">
                        {current.content}
                    </pre>
                )}
            </div>
        </Section>
    );
}

function Settings({
    ticket,
    args,
    options,
}: {
    ticket: TicketDetail;
    args: { current_team: string; project: string; ticket: number };
    options: TicketFormOptions;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Section title="Settings">
            {!open ? (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(true)}
                    disabled={ticket.status === 'running'}
                >
                    Edit feature and agent settings
                </Button>
            ) : (
                <Form
                    {...update.form(args)}
                    className="space-y-5"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <TicketFormFields
                                options={options}
                                values={ticket}
                                errors={errors}
                                titleLocked={ticket.branch !== null}
                            />
                            <InputError message={errors.ticket} />
                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}

            {ticket.status !== 'running' ? (
                <Form
                    {...destroy.form(args)}
                    onBefore={() =>
                        window.confirm(
                            `Delete #${ticket.number}? Its branch stays in the repository.`,
                        )
                    }
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            className="text-red-700 dark:text-red-400"
                            disabled={processing}
                        >
                            <Trash2 /> Delete card
                        </Button>
                    )}
                </Form>
            ) : null}
        </Section>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2">
            <h3 className="text-sm font-medium text-muted-foreground">
                {title}
            </h3>
            {children}
        </section>
    );
}
