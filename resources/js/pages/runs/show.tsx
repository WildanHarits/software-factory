import { Form, Head } from '@inertiajs/react';
import {
    Bot,
    ChevronRight,
    CircleAlert,
    ExternalLink,
    GitPullRequest,
    Terminal,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import RunStatusBadge, {
    formatCost,
    formatDuration,
} from '@/components/runs/run-status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as showProject } from '@/routes/projects';
import { cancel, events as eventsRoute, show } from '@/routes/projects/runs';
import type { RunEvent, RunSummary } from '@/types';

type Props = {
    team: { name: string; slug: string };
    project: {
        name: string;
        slug: string;
        repo_full_name: string;
        repo_web_url: string;
    };
    run: RunSummary;
    events: RunEvent[];
    canCancel: boolean;
};

const POLL_MS = 1500;

export default function ShowRun(props: Props) {
    const { team, project, canCancel } = props;
    const [run, setRun] = useState(props.run);
    const [events, setEvents] = useState(props.events);
    const [now, setNow] = useState(() => Date.now());
    const logRef = useRef<HTMLDivElement>(null);
    const stickToBottom = useRef(true);
    const routeArgs = {
        current_team: team.slug,
        project: project.slug,
        run: run.id,
    };

    // Keep in sync when Inertia reloads the page (e.g. after pressing Cancel).
    useEffect(() => {
        setRun(props.run);
    }, [props.run]);

    // While the run is active, fetch new log lines and the latest status.
    useEffect(() => {
        if (!run.is_active) {
            return;
        }

        let cancelled = false;
        const lastSeq = () => events[events.length - 1]?.seq ?? 0;

        const timer = window.setInterval(async () => {
            try {
                const response = await fetch(
                    eventsRoute.url(routeArgs, {
                        query: { after: String(lastSeq()) },
                    }),
                    { headers: { Accept: 'application/json' } },
                );

                if (!response.ok || cancelled) {
                    return;
                }

                const body = (await response.json()) as {
                    run: RunSummary;
                    events: RunEvent[];
                };

                setRun(body.run);

                if (body.events.length > 0) {
                    setEvents((current) => {
                        const known = new Set(
                            current.map((event) => event.seq),
                        );

                        return [
                            ...current,
                            ...body.events.filter(
                                (event) => !known.has(event.seq),
                            ),
                        ];
                    });
                }
            } catch {
                // Try again on the next tick.
            }

            setNow(Date.now());
        }, POLL_MS);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [run.is_active, events.length]);

    // Follow new output unless the reader scrolled up.
    useEffect(() => {
        const element = logRef.current;

        if (element && stickToBottom.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [events.length]);

    const duration = formatDuration(
        run.started_at,
        run.finished_at ?? (run.is_active ? new Date(now).toISOString() : null),
    );

    return (
        <>
            <Head title={`${run.type_label} · ${project.name}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-3">
                            <Heading
                                title={run.type_label}
                                description={`${project.name} · run #${run.id}`}
                            />
                        </div>
                        <div className="-mt-4">
                            <RunStatusBadge
                                status={run.status}
                                label={run.status_label}
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {run.pull_request_url ? (
                            <Button asChild>
                                <a
                                    href={run.pull_request_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <GitPullRequest />
                                    Open pull request
                                    {run.pull_request_number
                                        ? ` #${run.pull_request_number}`
                                        : ''}
                                </a>
                            </Button>
                        ) : null}

                        {canCancel &&
                        (run.status === 'queued' ||
                            run.status === 'running') ? (
                            <Form
                                {...cancel.form(routeArgs)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Cancel run
                                    </Button>
                                )}
                            </Form>
                        ) : null}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    <Meta label="Model" value={run.model_label ?? '—'} />
                    <Meta label="Effort" value={run.effort ?? '—'} />
                    <Meta label="Duration" value={duration ?? 'Waiting'} />
                    <Meta label="Cost" value={formatCost(run.cost_usd)} />
                    <Meta
                        label="Tokens"
                        value={`${(run.input_tokens + run.cache_read_tokens + run.cache_write_tokens).toLocaleString()} in · ${run.output_tokens.toLocaleString()} out`}
                    />
                    <Meta label="Started by" value={run.triggered_by ?? '—'} />
                </dl>

                {run.status === 'queued' ? (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        Waiting for a worker. If this doesn't start within a few
                        seconds, make sure Horizon is running (
                        <code>composer run dev</code>).
                    </p>
                ) : null}

                {run.error ? (
                    <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        <p className="break-words whitespace-pre-wrap">
                            {run.error}
                        </p>
                    </div>
                ) : null}

                <section className="space-y-2">
                    <h2 className="text-base font-medium">Log</h2>
                    <div
                        ref={logRef}
                        onScroll={(event) => {
                            const element = event.currentTarget;
                            stickToBottom.current =
                                element.scrollHeight -
                                    element.scrollTop -
                                    element.clientHeight <
                                40;
                        }}
                        className="max-h-[65vh] overflow-y-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs"
                        data-test="run-log"
                    >
                        {events.length === 0 ? (
                            <p className="text-muted-foreground">
                                Nothing logged yet.
                            </p>
                        ) : (
                            events.map((event) => (
                                <LogLine key={event.seq} event={event} />
                            ))
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

function Meta({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="truncate">{value}</dd>
        </div>
    );
}

function LogLine({ event }: { event: RunEvent }) {
    switch (event.kind) {
        case 'stage':
            return (
                <div className="mt-3 mb-1 flex items-center gap-1.5 font-sans text-sm font-medium first:mt-0">
                    <ChevronRight className="size-4 text-muted-foreground" />
                    {event.title}
                    {event.body && event.body.startsWith('http') ? (
                        <a
                            href={event.body}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-normal text-muted-foreground underline underline-offset-4"
                        >
                            {event.body}
                            <ExternalLink className="size-3" />
                        </a>
                    ) : null}
                </div>
            );
        case 'assistant':
            return (
                <div className="my-2 flex gap-2 font-sans text-sm">
                    <Bot className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <p className="break-words whitespace-pre-wrap">
                        {event.body}
                    </p>
                </div>
            );
        case 'tool':
            return (
                <Collapsible
                    icon={<Wrench className="size-3.5 shrink-0" />}
                    title={event.title ?? 'Tool'}
                    body={event.body}
                />
            );
        case 'tool_result':
            return (
                <Collapsible
                    icon={<Terminal className="size-3.5 shrink-0" />}
                    title={event.title ?? firstLine(event.body)}
                    body={event.body}
                    className={
                        event.title === 'Tool error'
                            ? 'text-red-700 dark:text-red-400'
                            : 'text-muted-foreground'
                    }
                />
            );
        case 'result':
        case 'system':
            return (
                <div className="my-2 font-sans text-sm font-medium">
                    {event.title}
                </div>
            );
        case 'error':
            return (
                <div className="my-1 font-sans text-sm text-red-700 dark:text-red-400">
                    {event.title ? <strong>{event.title}: </strong> : null}
                    {event.body}
                </div>
            );
        default:
            return (
                <div className="break-words whitespace-pre-wrap text-muted-foreground">
                    {event.body}
                </div>
            );
    }
}

function Collapsible({
    icon,
    title,
    body,
    className,
}: {
    icon: React.ReactNode;
    title: string;
    body: string | null;
    className?: string;
}) {
    const [open, setOpen] = useState(false);

    if (!body) {
        return (
            <div className={cn('flex items-center gap-1.5 py-0.5', className)}>
                {icon}
                <span className="truncate">{title}</span>
            </div>
        );
    }

    return (
        <div className={cn('py-0.5', className)}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="flex w-full items-center gap-1.5 text-left hover:text-foreground"
                aria-expanded={open}
            >
                {icon}
                <span className="truncate">{title}</span>
            </button>
            {open ? (
                <pre className="mt-1 ml-5 overflow-x-auto rounded border bg-background p-2 whitespace-pre-wrap">
                    {body}
                </pre>
            ) : null}
        </div>
    );
}

function firstLine(text: string | null): string {
    const line = (text ?? '').split('\n')[0] ?? '';

    return line.length > 120 ? `${line.slice(0, 120)}…` : line || 'Output';
}

ShowRun.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard(props.team.slug) },
        {
            title: props.project.name,
            href: showProject({
                current_team: props.team.slug,
                project: props.project.slug,
            }),
        },
        {
            title: `Run #${props.run.id}`,
            href: show({
                current_team: props.team.slug,
                project: props.project.slug,
                run: props.run.id,
            }),
        },
    ],
});
