import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    ExternalLink,
    GitBranch,
    GitPullRequest,
    Lock,
    Plus,
    RefreshCw,
    ScrollText,
    Settings,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import Board from '@/components/board/board';
import NewTicketDialog from '@/components/board/new-ticket-dialog';
import TicketPanel from '@/components/board/ticket-panel';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import CredentialStatus from '@/components/projects/credential-status';
import RunStatusBadge, { formatCost } from '@/components/runs/run-status-badge';
import StartRunDialog from '@/components/runs/start-run-dialog';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit, refresh, show } from '@/routes/projects';
import { constitution, setup, show as showRun } from '@/routes/projects/runs';
import type {
    BoardColumn,
    Option,
    ProjectSummary,
    RunnerProblem,
    RunSummary,
    TicketCard,
    TicketDetail,
} from '@/types';

type Props = {
    project: ProjectSummary;
    canManage: boolean;
    team: { name: string; slug: string };
    runs: RunSummary[];
    setupPullRequest: RunSummary | null;
    runnerProblems: RunnerProblem[];
    models: Option[];
    efforts: Option[];
    approvalModes: Option[];
    speckitVersion: string;
    columns: BoardColumn[];
    tickets: TicketCard[];
    selectedTicket: TicketDetail | null;
};

const POLL_MS = 3000;

export default function ShowProject({
    project,
    canManage,
    team,
    runs,
    setupPullRequest,
    runnerProblems,
    models,
    efforts,
    approvalModes,
    speckitVersion,
    columns,
    tickets,
    selectedTicket,
}: Props) {
    const routeArgs = { current_team: team.slug, project: project.slug };
    const [openTicket, setOpenTicket] = useState<number | null>(
        selectedTicket?.number ?? null,
    );
    const ticketOptions = { models, efforts, approvalModes };

    // Open the panel when the server sends a ticket (e.g. right after creating one).
    useEffect(() => {
        if (selectedTicket) {
            setOpenTicket(selectedTicket.number);
        }
    }, [selectedTicket?.number]); // eslint-disable-line react-hooks/exhaustive-deps

    // Refresh the board while anything is running.
    const { start, stop } = usePoll(
        POLL_MS,
        { only: ['tickets', 'selectedTicket', 'runs'] },
        { autoStart: false },
    );
    const anyRunning =
        tickets.some((ticket) => ticket.status === 'running') ||
        runs.some((run) => run.is_active);

    useEffect(() => {
        if (anyRunning) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [anyRunning, start, stop]);

    const openPanel = (number: number | null) => {
        setOpenTicket(number);
        router.get(show.url(routeArgs), number ? { ticket: number } : {}, {
            only: ['selectedTicket'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title={project.name} />

            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <Heading
                            title={project.name}
                            description={project.description ?? undefined}
                        />
                        <a
                            href={project.repo_web_url}
                            target="_blank"
                            rel="noreferrer"
                            className="-mt-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                        >
                            {project.repo_private ? (
                                <Lock className="size-3.5" />
                            ) : null}
                            {project.provider_label} · {project.repo_full_name}
                            <ExternalLink className="size-3.5" />
                        </a>
                    </div>

                    {canManage ? (
                        <div className="flex items-center gap-2">
                            <Form
                                {...refresh.form(routeArgs)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <RefreshCw
                                                className={
                                                    processing
                                                        ? 'animate-spin'
                                                        : undefined
                                                }
                                            />
                                            Refresh
                                        </Button>
                                        <InputError
                                            className="mt-2 max-w-xs"
                                            message={errors.refresh}
                                        />
                                    </>
                                )}
                            </Form>
                            <Button variant="outline" asChild>
                                <Link href={edit(routeArgs)}>
                                    <Settings /> Settings
                                </Link>
                            </Button>
                        </div>
                    ) : null}
                </div>

                {runnerProblems.length > 0 ? (
                    <div
                        className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                        data-test="runner-problems"
                    >
                        <p className="flex items-center gap-1.5 font-medium">
                            <TriangleAlert className="size-4" />
                            Runs can't work until this is fixed
                        </p>
                        <ul className="space-y-1">
                            {runnerProblems.map((problem) => (
                                <li key={problem.key}>
                                    <strong>{problem.label}:</strong>{' '}
                                    {problem.detail}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <div className="grid gap-4 md:grid-cols-3">
                    <Card title="Repository">
                        <p className="flex items-center gap-1.5 text-sm">
                            <GitBranch className="size-4 text-muted-foreground" />
                            {project.default_branch}
                        </p>
                        <CredentialStatus
                            credential={project.git_credential}
                            missing="No git token. Pick one in Settings."
                        />
                    </Card>

                    <Card title="Spec Kit">
                        {project.speckit_initialized ? (
                            <>
                                <p className="flex items-center gap-1.5 text-sm">
                                    <CircleCheck className="size-4 text-green-600 dark:text-green-400" />
                                    Set up in this repository
                                </p>
                                {canManage ? (
                                    <StartRunDialog
                                        form={constitution.form(routeArgs)}
                                        title="Update the constitution"
                                        description="The agent edits .specify/memory/constitution.md and opens a pull request for you to review."
                                        field={{
                                            name: 'instructions',
                                            label: 'What should change?',
                                            placeholder:
                                                'e.g. Every feature needs automated tests. Prefer boring, well-known libraries. Support the last two versions of each browser.',
                                            required: true,
                                        }}
                                        submitLabel="Start"
                                        models={models}
                                        efforts={efforts}
                                        defaultModel={project.default_model}
                                        defaultEffort={project.default_effort}
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="mt-1"
                                        >
                                            <ScrollText /> Update constitution
                                        </Button>
                                    </StartRunDialog>
                                ) : null}
                            </>
                        ) : (
                            <>
                                <p className="flex items-center gap-1.5 text-sm">
                                    <CircleDashed className="size-4 text-muted-foreground" />
                                    Not set up yet
                                </p>
                                {setupPullRequest?.pull_request_url ? (
                                    <p className="text-xs text-muted-foreground">
                                        Setup pull request{' '}
                                        <a
                                            href={
                                                setupPullRequest.pull_request_url
                                            }
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline underline-offset-4"
                                        >
                                            #
                                            {
                                                setupPullRequest.pull_request_number
                                            }
                                        </a>{' '}
                                        is open. Merge it, then press Refresh.
                                    </p>
                                ) : null}
                                {canManage ? (
                                    <StartRunDialog
                                        form={setup.form(routeArgs)}
                                        title="Set up Spec Kit"
                                        description={`Runs specify init (Spec Kit v${speckitVersion}) on a new branch and opens a pull request. Nothing is pushed to ${project.default_branch} directly.`}
                                        field={{
                                            name: 'principles',
                                            label: 'Project principles (optional)',
                                            placeholder:
                                                'e.g. Laravel + React. Every feature has tests. Accessibility matters. Keep dependencies few.',
                                            help: project.ai_credential
                                                ? 'If you fill this in, the agent writes the constitution from it in the same pull request.'
                                                : 'Writing the constitution needs an Anthropic key on this project. Leave empty to only install Spec Kit.',
                                        }}
                                        submitLabel="Start setup"
                                        models={models}
                                        efforts={efforts}
                                        defaultModel={project.default_model}
                                        defaultEffort={project.default_effort}
                                    >
                                        <Button size="sm" className="mt-1">
                                            <Sparkles />
                                            {setupPullRequest
                                                ? 'Set up again'
                                                : 'Set up Spec Kit'}
                                        </Button>
                                    </StartRunDialog>
                                ) : null}
                            </>
                        )}
                    </Card>

                    <Card title="Agent defaults">
                        <p className="text-sm">
                            {project.default_model_label} ·{' '}
                            {project.default_effort_label} effort
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {project.approval_mode_label}
                        </p>
                        <CredentialStatus
                            credential={project.ai_credential}
                            missing="No Anthropic key. Agents can't run until one is set."
                        />
                    </Card>
                </div>

                <section className="space-y-3">
                    <h2 className="text-base font-medium">Board</h2>
                    {!project.speckit_initialized ? (
                        <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                            You can add features now. Phases start once Spec Kit
                            is set up in the repository.
                        </p>
                    ) : null}
                    <Board
                        columns={columns}
                        tickets={tickets}
                        routeArgs={routeArgs}
                        canWork
                        onOpen={(ticket) => openPanel(ticket.number)}
                        backlogAction={
                            <NewTicketDialog
                                routeArgs={routeArgs}
                                options={ticketOptions}
                                defaults={{
                                    model: project.default_model,
                                    effort: project.default_effort,
                                    approval_mode: project.approval_mode,
                                }}
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="justify-start"
                                    data-test="new-ticket-button"
                                >
                                    <Plus /> New feature
                                </Button>
                            </NewTicketDialog>
                        }
                    />
                </section>

                <TicketPanel
                    ticket={
                        selectedTicket?.number === openTicket
                            ? selectedTicket
                            : null
                    }
                    open={openTicket !== null}
                    onClose={() => openPanel(null)}
                    routeArgs={routeArgs}
                    canWork
                    repoWebUrl={project.repo_web_url}
                    options={ticketOptions}
                />

                <section className="space-y-3">
                    <h2 className="text-base font-medium">Project runs</h2>
                    {runs.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            Spec Kit setup and constitution runs show up here.
                            Feature runs are on each card.
                        </p>
                    ) : (
                        <div className="divide-y rounded-lg border">
                            {runs.map((run) => (
                                <Link
                                    key={run.id}
                                    href={showRun({
                                        ...routeArgs,
                                        run: run.id,
                                    })}
                                    className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm hover:bg-accent/50"
                                    data-test="run-row"
                                >
                                    <span className="flex min-w-0 items-center gap-3">
                                        <RunStatusBadge
                                            status={run.status}
                                            label={run.status_label}
                                        />
                                        <span className="truncate font-medium">
                                            {run.type_label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            #{run.id}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3 text-muted-foreground">
                                        {run.pull_request_number ? (
                                            <span className="flex items-center gap-1">
                                                <GitPullRequest className="size-3.5" />
                                                #{run.pull_request_number}
                                            </span>
                                        ) : null}
                                        <span>{formatCost(run.cost_usd)}</span>
                                        <span>{run.triggered_by ?? ''}</span>
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-2 rounded-lg border p-4">
            <h3 className="text-sm font-medium text-muted-foreground">
                {title}
            </h3>
            {children}
        </div>
    );
}

ShowProject.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard(props.team.slug) },
        {
            title: props.project.name,
            href: show({
                current_team: props.team.slug,
                project: props.project.slug,
            }),
        },
    ],
});
