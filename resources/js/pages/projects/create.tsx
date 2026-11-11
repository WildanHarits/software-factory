import { Form, Head } from '@inertiajs/react';
import { FolderGit2, FolderPlus, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import LabeledSelect from '@/components/projects/labeled-select';
import {
    AgentDefaultsFields,
    credentialOptions,
    TokenLinks,
} from '@/components/projects/project-settings-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { namespaces as namespacesRoute } from '@/routes/credentials';
import { create, store } from '@/routes/projects';
import type { ProjectFormOptions, ProjectSettingsValues } from '@/types';

type Mode = 'new' | 'existing';

type RepositoryNamespace = {
    id: string;
    path: string;
    kind: 'user' | 'org' | 'group';
};

type Props = ProjectFormOptions & {
    defaults: ProjectSettingsValues;
};

export default function CreateProject(props: Props) {
    const { gitCredentials, team, defaults } = props;
    const [mode, setMode] = useState<Mode>('existing');
    const [credentialId, setCredentialId] = useState<string>(
        gitCredentials[0] ? String(gitCredentials[0].id) : '',
    );
    const [repoName, setRepoName] = useState('');
    const [name, setName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);

    const selected = gitCredentials.find(
        (credential) => String(credential.id) === credentialId,
    );

    if (gitCredentials.length === 0) {
        return (
            <>
                <Head title="New project" />
                <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                    <Heading
                        title="New project"
                        description="A project connects a git repository to Software Factory."
                    />
                    <div className="rounded-lg border border-dashed p-6 text-sm">
                        <p className="font-medium">
                            Add a GitHub or GitLab token first
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Projects need a token that can push to the
                            repository. Add one in <TokenLinks team={team} />,
                            then come back here.
                        </p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="New project" />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    title="New project"
                    description="A project connects a git repository to Software Factory. Features become cards on its board."
                />

                <Form
                    {...store.form(team.slug)}
                    className="space-y-10"
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="mode" value={mode} />

                            <section className="space-y-6">
                                <h2 className="text-base font-medium">
                                    Repository
                                </h2>

                                <div
                                    className="grid gap-3 sm:grid-cols-2"
                                    role="radiogroup"
                                    aria-label="Repository"
                                >
                                    <ModeCard
                                        active={mode === 'existing'}
                                        onSelect={() => setMode('existing')}
                                        icon={<FolderGit2 className="size-5" />}
                                        title="Connect existing"
                                        description="Use a repository you already have."
                                    />
                                    <ModeCard
                                        active={mode === 'new'}
                                        onSelect={() => setMode('new')}
                                        icon={<FolderPlus className="size-5" />}
                                        title="Create new"
                                        description="Make a new repository with this token."
                                    />
                                </div>

                                <LabeledSelect
                                    id="git_credential_id"
                                    label="Git token"
                                    options={credentialOptions(gitCredentials)}
                                    value={credentialId}
                                    onValueChange={setCredentialId}
                                    help={
                                        <>
                                            The token agents use to clone, push
                                            and open PRs/MRs. Manage tokens in{' '}
                                            <TokenLinks team={team} />.
                                        </>
                                    }
                                    error={errors.git_credential_id}
                                />

                                {mode === 'existing' ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="repository">
                                            Repository
                                        </Label>
                                        <Input
                                            id="repository"
                                            name="repository"
                                            placeholder={
                                                selected?.provider === 'gitlab'
                                                    ? 'group/project or https://gitlab.com/group/project'
                                                    : 'owner/repo or https://github.com/owner/repo'
                                            }
                                            autoComplete="off"
                                            onChange={(event) => {
                                                const last =
                                                    event.target.value
                                                        .replace(/\.git$/, '')
                                                        .split('/')
                                                        .filter(Boolean)
                                                        .pop() ?? '';
                                                if (!nameTouched) {
                                                    setName(last);
                                                }
                                            }}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            The token needs write access.
                                        </p>
                                        <InputError
                                            message={errors.repository}
                                        />
                                    </div>
                                ) : (
                                    <NewRepositoryFields
                                        credentialId={credentialId}
                                        repoName={repoName}
                                        onRepoNameChange={(value) => {
                                            setRepoName(value);
                                            if (!nameTouched) {
                                                setName(value);
                                            }
                                        }}
                                        errors={errors}
                                    />
                                )}
                            </section>

                            <section className="space-y-6">
                                <h2 className="text-base font-medium">
                                    Project
                                </h2>

                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        value={name}
                                        onChange={(event) => {
                                            setName(event.target.value);
                                            setNameTouched(true);
                                        }}
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">
                                        Description (optional)
                                    </Label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        rows={3}
                                    />
                                    <InputError message={errors.description} />
                                </div>
                            </section>

                            <section className="space-y-6">
                                <h2 className="text-base font-medium">
                                    Agent defaults
                                </h2>
                                <AgentDefaultsFields
                                    options={props}
                                    values={defaults}
                                    errors={errors}
                                />
                            </section>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="create-project-submit"
                                >
                                    {processing ? (
                                        <LoaderCircle className="animate-spin" />
                                    ) : null}
                                    {mode === 'new'
                                        ? 'Create repository and project'
                                        : 'Connect and create project'}
                                </Button>
                                <Button variant="ghost" asChild>
                                    <a href={dashboard(team.slug).url}>
                                        Cancel
                                    </a>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function ModeCard({
    active,
    onSelect,
    icon,
    title,
    description,
}: {
    active: boolean;
    onSelect: () => void;
    icon: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={active}
            onClick={onSelect}
            className={cn(
                'flex items-start gap-3 rounded-lg border p-4 text-left transition-colors',
                active ? 'border-primary bg-accent' : 'hover:bg-accent/50',
            )}
        >
            <span className="mt-0.5 text-muted-foreground">{icon}</span>
            <span>
                <span className="block font-medium">{title}</span>
                <span className="block text-sm text-muted-foreground">
                    {description}
                </span>
            </span>
        </button>
    );
}

function NewRepositoryFields({
    credentialId,
    repoName,
    onRepoNameChange,
    errors,
}: {
    credentialId: string;
    repoName: string;
    onRepoNameChange: (value: string) => void;
    errors: Record<string, string | undefined>;
}) {
    const [namespaces, setNamespaces] = useState<RepositoryNamespace[]>([]);
    const [namespace, setNamespace] = useState('');
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);

    // Ask the provider where this token can create repositories.
    useEffect(() => {
        if (!credentialId) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setLoadError(null);

        fetch(namespacesRoute.url(Number(credentialId)), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const body = await response.json();

                if (!response.ok) {
                    throw new Error(
                        body.message ??
                            'Could not load accounts for this token.',
                    );
                }

                const list = body.namespaces as RepositoryNamespace[];
                setNamespaces(list);
                setNamespace(list[0]?.id ?? '');
            })
            .catch((error: Error) => {
                if (error.name !== 'AbortError') {
                    setNamespaces([]);
                    setNamespace('');
                    setLoadError(error.message);
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [credentialId]);

    return (
        <div className="grid gap-6">
            <div className="grid gap-6 sm:grid-cols-2">
                <LabeledSelect
                    id="repo_namespace"
                    label="Owner"
                    options={namespaces.map((item) => ({
                        value: item.id,
                        label: `${item.path}${item.kind === 'user' ? ' (you)' : ''}`,
                    }))}
                    value={namespace}
                    onValueChange={setNamespace}
                    placeholder={loading ? 'Loading…' : 'Pick an owner'}
                    disabled={loading || namespaces.length === 0}
                    error={loadError ?? errors.repo_namespace}
                />

                <div className="grid gap-2">
                    <Label htmlFor="repo_name">Repository name</Label>
                    <Input
                        id="repo_name"
                        name="repo_name"
                        value={repoName}
                        onChange={(event) =>
                            onRepoNameChange(event.target.value)
                        }
                        placeholder="my-app"
                        autoComplete="off"
                    />
                    <InputError message={errors.repo_name} />
                </div>
            </div>

            <div className="flex items-start gap-3">
                <Checkbox
                    id="repo_private"
                    name="repo_private"
                    value="1"
                    defaultChecked
                />
                <div className="grid gap-1">
                    <Label htmlFor="repo_private">Private repository</Label>
                    <p className="text-xs text-muted-foreground">
                        It starts with a README so the default branch exists.
                    </p>
                </div>
            </div>
        </div>
    );
}

CreateProject.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Projects', href: dashboard(props.team.slug) },
        { title: 'New project', href: create(props.team.slug) },
    ],
});
