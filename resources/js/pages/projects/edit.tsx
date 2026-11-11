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
import { destroy, edit, show, update } from '@/routes/projects';
import type { ProjectFormOptions, ProjectSummary } from '@/types';

type Props = ProjectFormOptions & {
    project: ProjectSummary;
};

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
