import { Head, Link, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, FolderGit2, Lock, Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, show } from '@/routes/projects';
import type { DashboardInvitation, ProjectSummary } from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
    projects: ProjectSummary[];
    canManageProjects: boolean;
};

export default function Dashboard({
    pendingInvitations = [],
    projects,
    canManageProjects,
}: Props) {
    const { currentTeam } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    return (
        <>
            <Head title="Projects" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Projects"
                        description={`Repositories ${currentTeam?.name ?? 'this team'} builds with Spec Kit.`}
                    />
                    {canManageProjects ? (
                        <Button asChild>
                            <Link
                                href={create(teamSlug)}
                                data-test="new-project-button"
                            >
                                <Plus /> New project
                            </Link>
                        </Button>
                    ) : null}
                </div>

                {projects.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <FolderGit2 className="mx-auto mb-3 size-6 text-muted-foreground" />
                        <p className="font-medium">No projects yet</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {canManageProjects
                                ? 'Create a new repository or connect one you already have.'
                                : 'Ask a team owner or admin to add a project.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <ProjectCard
                                key={project.id}
                                project={project}
                                teamSlug={teamSlug}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function ProjectCard({
    project,
    teamSlug,
}: {
    project: ProjectSummary;
    teamSlug: string;
}) {
    const problems = [
        !project.git_credential || !project.git_credential.last_verified_at
            ? 'Git token missing or not working'
            : null,
        !project.ai_credential && !project.ai_uses_member_subscription
            ? 'No AI key'
            : null,
    ].filter(Boolean);

    return (
        <Link
            href={show({ current_team: teamSlug, project: project.slug })}
            data-test="project-card"
            className="flex flex-col gap-3 rounded-xl border p-4 transition-colors hover:bg-accent/50"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="font-medium">{project.name}</span>
                <Badge variant="secondary">{project.provider_label}</Badge>
            </div>

            <span className="flex items-center gap-1.5 truncate text-sm text-muted-foreground">
                {project.repo_private ? <Lock className="size-3.5" /> : null}
                {project.repo_full_name}
            </span>

            {project.description ? (
                <p className="line-clamp-2 text-sm text-muted-foreground">
                    {project.description}
                </p>
            ) : null}

            <div className="mt-auto flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <span className="flex items-center gap-1 text-muted-foreground">
                    {project.speckit_initialized ? (
                        <CircleCheck className="size-3.5 text-green-600 dark:text-green-400" />
                    ) : (
                        <CircleAlert className="size-3.5" />
                    )}
                    {project.speckit_initialized
                        ? 'Spec Kit ready'
                        : 'Spec Kit not set up'}
                </span>
                {problems.map((problem) => (
                    <span
                        key={problem}
                        className="flex items-center gap-1 text-amber-700 dark:text-amber-400"
                    >
                        <CircleAlert className="size-3.5" />
                        {problem}
                    </span>
                ))}
            </div>
        </Link>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
