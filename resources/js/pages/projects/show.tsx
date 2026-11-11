import { Form, Head, Link } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    ExternalLink,
    GitBranch,
    Lock,
    RefreshCw,
    Settings,
} from 'lucide-react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import BoardPreview from '@/components/projects/board-preview';
import CredentialStatus from '@/components/projects/credential-status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit, refresh, show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

type Props = {
    project: ProjectSummary;
    canManage: boolean;
    team: { name: string; slug: string };
};

export default function ShowProject({ project, canManage, team }: Props) {
    const routeArgs = { current_team: team.slug, project: project.slug };

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
                            <p className="flex items-center gap-1.5 text-sm">
                                <CircleCheck className="size-4 text-green-600 dark:text-green-400" />
                                Set up in this repository
                            </p>
                        ) : (
                            <>
                                <p className="flex items-center gap-1.5 text-sm">
                                    <CircleDashed className="size-4 text-muted-foreground" />
                                    Not set up yet
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Setting it up from here (specify init and
                                    the constitution) comes with the agent
                                    runner in Phase 3.
                                </p>
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
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-medium">Board</h2>
                        <Badge variant="secondary">Coming in Phase 4</Badge>
                    </div>
                    <BoardPreview />
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
