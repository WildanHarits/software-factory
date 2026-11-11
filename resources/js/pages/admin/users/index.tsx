import { Form, Head, usePage } from '@inertiajs/react';
import { Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { destroy, index, store, update } from '@/routes/admin/users';
import type { RoleOption } from '@/types';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_super_admin: boolean;
    teams_count: number;
    two_factor_enabled: boolean;
    created_at: string | null;
};

type TeamOption = { id: number; name: string };

type Props = {
    users: AdminUser[];
    teams: TeamOption[];
    roles: RoleOption[];
};

export default function AdminUsersIndex({ users, teams, roles }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Users" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Users"
                        description="There is no public sign-up. Create accounts here, then add people to teams."
                    />

                    <CreateUserDialog teams={teams} roles={roles}>
                        <Button data-test="new-user-button">
                            <Plus /> New user
                        </Button>
                    </CreateUserDialog>
                </div>

                <div className="divide-y rounded-lg border">
                    {users.map((user) => {
                        const isYou = user.id === auth.user.id;

                        return (
                            <div
                                key={user.id}
                                data-test="user-row"
                                className="flex flex-wrap items-center justify-between gap-4 p-4"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {user.name}
                                        </span>
                                        {user.is_super_admin ? (
                                            <Badge>
                                                <ShieldCheck /> Super admin
                                            </Badge>
                                        ) : null}
                                        {isYou ? (
                                            <Badge variant="secondary">
                                                You
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {user.email}
                                    </p>
                                </div>

                                <div className="flex items-center gap-4">
                                    <span className="text-sm text-muted-foreground">
                                        {user.teams_count}{' '}
                                        {user.teams_count === 1
                                            ? 'team'
                                            : 'teams'}
                                        {user.two_factor_enabled
                                            ? ' · 2FA on'
                                            : ''}
                                    </span>

                                    <div className="flex items-center gap-1">
                                        <EditUserDialog user={user}>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Edit ${user.name}`}
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </Button>
                                        </EditUserDialog>

                                        {!isYou ? (
                                            <DeleteUserDialog user={user}>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Delete ${user.name}`}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </DeleteUserDialog>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

function CreateUserDialog({
    teams,
    roles,
    children,
}: {
    teams: TeamOption[];
    roles: RoleOption[];
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [teamId, setTeamId] = useState('none');

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                setTeamId('none');
            }}
        >
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...store.form()}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Create a user</DialogTitle>
                                <DialogDescription>
                                    They get a personal team automatically.
                                    Share the password with them; they can
                                    change it in Settings.
                                </DialogDescription>
                            </DialogHeader>

                            <UserFields errors={errors} />

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="team_id">
                                        Add to team (optional)
                                    </Label>
                                    <Select
                                        name="team_id"
                                        value={teamId}
                                        onValueChange={setTeamId}
                                    >
                                        <SelectTrigger id="team_id">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                No team
                                            </SelectItem>
                                            {teams.map((team) => (
                                                <SelectItem
                                                    key={team.id}
                                                    value={String(team.id)}
                                                >
                                                    {team.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.team_id} />
                                </div>

                                {teamId !== 'none' ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="role">Role</Label>
                                        <Select
                                            name="role"
                                            defaultValue="member"
                                        >
                                            <SelectTrigger id="role">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem
                                                        key={role.value}
                                                        value={role.value}
                                                    >
                                                        {role.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.role} />
                                    </div>
                                ) : null}
                            </div>

                            <SuperAdminField errors={errors} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="create-user-submit"
                                >
                                    Create user
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditUserDialog({
    user,
    children,
}: {
    user: AdminUser;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...update.form(user.id)}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Edit {user.name}</DialogTitle>
                                <DialogDescription>
                                    Leave the password empty to keep the current
                                    one.
                                </DialogDescription>
                            </DialogHeader>

                            <UserFields user={user} errors={errors} />

                            <div className="grid gap-2">
                                <Label htmlFor="password">New password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="new-password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <SuperAdminField
                                errors={errors}
                                defaultChecked={user.is_super_admin}
                            />

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

function DeleteUserDialog({
    user,
    children,
}: {
    user: AdminUser;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    {...destroy.form(user.id)}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Delete {user.name}?</DialogTitle>
                                <DialogDescription>
                                    This removes their account, their personal
                                    team and their team memberships. It cannot
                                    be undone.
                                </DialogDescription>
                            </DialogHeader>

                            <InputError message={errors.user} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Delete user
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function UserFields({
    user,
    errors,
}: {
    user?: AdminUser;
    errors: Record<string, string | undefined>;
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={user?.name}
                    required
                    autoComplete="off"
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="email">Email</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    defaultValue={user?.email}
                    required
                    autoComplete="off"
                />
                <InputError message={errors.email} />
            </div>
        </>
    );
}

function SuperAdminField({
    errors,
    defaultChecked = false,
}: {
    errors: Record<string, string | undefined>;
    defaultChecked?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex items-start gap-3">
                <Checkbox
                    id="is_super_admin"
                    name="is_super_admin"
                    value="1"
                    defaultChecked={defaultChecked}
                />
                <div className="grid gap-1">
                    <Label htmlFor="is_super_admin">Super admin</Label>
                    <p className="text-sm text-muted-foreground">
                        Can create, edit and delete every user.
                    </p>
                </div>
            </div>
            <InputError message={errors.is_super_admin} />
        </div>
    );
}

AdminUsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: index(),
        },
    ],
};
