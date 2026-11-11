import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import LabeledSelect from '@/components/projects/labeled-select';
import {
    AgentDefaultsFields,
    credentialOptions,
    TokenLinks,
} from '@/components/projects/project-settings-fields';
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
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import {
    destroy,
    edit,
    show,
    update,
    webhook as webhookRoute,
} from '@/routes/projects';
import type { ProjectFormOptions, ProjectSummary } from '@/types';

type Webhook = {
    url: string;
    secret: string;
    registered: boolean;
    last_received_at: string | null;
    reachable: boolean;
    sync_every_minutes: number;
};

type Props = ProjectFormOptions & {
    project: ProjectSummary;
    webhook: Webhook;
};

function WebhookSection({
    webhook,
    providerLabel,
    routeArgs,
}: {
    webhook: Webhook;
    providerLabel: string;
    routeArgs: { current_team: string; project: string };
}) {
    const [showSecret, setShowSecret] = useState(false);

    return (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Pull request updates"
                description={`When a pull request is merged on ${providerLabel}, its card moves to Done. A webhook tells the app right away; without one, open pull requests are checked every ${webhook.sync_every_minutes} minutes.`}
            />

            <dl className="grid gap-3 rounded-lg border p-4 text-sm">
                <div className="grid gap-1">
                    <dt className="text-xs text-muted-foreground">Status</dt>
                    <dd>
                        {webhook.last_received_at
                            ? `Working. Last event ${new Date(webhook.last_received_at).toLocaleString()}.`
                            : webhook.registered
                              ? 'Added, no events received yet.'
                              : 'Not set up. Using the background check.'}
                    </dd>
                </div>
                <div className="grid gap-1">
                    <dt className="text-xs text-muted-foreground">
                        Payload URL
                    </dt>
                    <dd className="font-mono text-xs break-all">
                        {webhook.url}
                    </dd>
                </div>
                <div className="grid gap-1">
                    <dt className="text-xs text-muted-foreground">
                        {providerLabel === 'GitLab' ? 'Secret token' : 'Secret'}
                    </dt>
                    <dd className="flex flex-wrap items-center gap-2">
                        <code className="font-mono text-xs break-all">
                            {showSecret ? webhook.secret : '••••••••••••••••'}
                        </code>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setShowSecret(!showSecret)}
                        >
                            {showSecret ? 'Hide' : 'Show'}
                        </Button>
                    </dd>
                </div>
                <div className="grid gap-1">
                    <dt className="text-xs text-muted-foreground">Events</dt>
                    <dd>
                        {providerLabel === 'GitLab'
                            ? 'Merge request events'
                            : 'Pull requests (content type: application/json)'}
                    </dd>
                </div>
            </dl>

            {!webhook.reachable ? (
                <p className="text-sm text-muted-foreground">
                    This address only works on your network, so {providerLabel}{' '}
                    can't reach it. To use a webhook, expose the app with a
                    tunnel (for example cloudflared or ngrok) and set APP_URL to
                    the tunnel's address.
                </p>
            ) : null}

            <Form
                {...webhookRoute.form(routeArgs)}
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing || !webhook.reachable}
                        >
                            {webhook.registered
                                ? 'Add the webhook again'
                                : `Add the webhook on ${providerLabel}`}
                        </Button>
                        <InputError className="mt-2" message={errors.webhook} />
                    </>
                )}
            </Form>
            <p className="text-xs text-muted-foreground">
                The git token needs permission to manage webhooks (GitHub:
                admin:repo_hook or Administration; GitLab: Maintainer). You can
                also add it by hand with the URL and secret above.
            </p>
        </section>
    );
}

export default function EditProject(props: Props) {
    const { project, team, gitCredentials } = props;
    const routeArgs = { current_team: team.slug, project: project.slug };
    const sameProviderTokens = gitCredentials.filter(
        (credential) => credential.provider === project.provider,
    );

    return (
        <>
            <Head title={`${project.name} settings`} />

            <div className="mx-auto w-full max-w-2xl space-y-10 p-4">
                <Heading
                    title="Project settings"
                    description={`${project.provider_label} · ${project.repo_full_name}`}
                />

                <Form
                    {...update.form(routeArgs)}
                    className="space-y-10"
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <section className="space-y-6">
                                <h2 className="text-base font-medium">
                                    Project
                                </h2>

                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={project.name}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">
                                        Description
                                    </Label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        rows={3}
                                        defaultValue={project.description ?? ''}
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <LabeledSelect
                                    id="git_credential_id"
                                    label="Git token"
                                    options={credentialOptions(
                                        sameProviderTokens,
                                    )}
                                    defaultValue={
                                        project.git_credential_id
                                            ? String(project.git_credential_id)
                                            : undefined
                                    }
                                    placeholder="Pick a token"
                                    help={
                                        <>
                                            Must be a {project.provider_label}{' '}
                                            token that can push to this
                                            repository. Manage tokens in{' '}
                                            <TokenLinks team={team} />.
                                        </>
                                    }
                                    error={errors.git_credential_id}
                                />
                            </section>

                            <section className="space-y-6">
                                <h2 className="text-base font-medium">
                                    Agent defaults
                                </h2>
                                <AgentDefaultsFields
                                    options={props}
                                    values={project}
                                    errors={errors}
                                />
                            </section>

                            <Button type="submit" disabled={processing}>
                                Save settings
                            </Button>
                        </>
                    )}
                </Form>

                <WebhookSection
                    webhook={props.webhook}
                    providerLabel={project.provider_label}
                    routeArgs={routeArgs}
                />

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Remove project"
                        description="Removes the project from Software Factory. The repository itself is not changed or deleted."
                    />
                    <RemoveProjectDialog
                        projectName={project.name}
                        routeArgs={routeArgs}
                    />
                </section>
            </div>
        </>
    );
}

function RemoveProjectDialog({
    projectName,
    routeArgs,
}: {
    projectName: string;
    routeArgs: { current_team: string; project: string };
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="destructive">Remove project</Button>
            </DialogTrigger>
            <DialogContent>
                <Form {...destroy.form(routeArgs)} className="space-y-6">
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Remove {projectName}?</DialogTitle>
                                <DialogDescription>
                                    The project disappears from Software
                                    Factory. Its repository, branches and pull
                                    requests stay as they are.
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
                                    Remove project
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

EditProject.layout = (props: Props) => {
    const routeArgs = {
        current_team: props.team.slug,
        project: props.project.slug,
    };

    return {
        breadcrumbs: [
            { title: 'Projects', href: dashboard(props.team.slug) },
            { title: props.project.name, href: show(routeArgs) },
            { title: 'Settings', href: edit(routeArgs) },
        ],
    };
};
