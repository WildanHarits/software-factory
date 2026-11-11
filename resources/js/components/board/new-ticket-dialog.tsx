import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import TicketFormFields from '@/components/board/ticket-form-fields';
import type { TicketFormOptions } from '@/components/board/ticket-form-fields';
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
import { store } from '@/routes/projects/tickets';

type Props = {
    routeArgs: { current_team: string; project: string };
    options: TicketFormOptions;
    defaults: { model: string; effort: string; approval_mode: string };
    children: ReactNode;
};

export default function NewTicketDialog({
    routeArgs,
    options,
    defaults,
    children,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <Form
                    key={String(open)}
                    {...store.form(routeArgs)}
                    className="space-y-6"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>New feature</DialogTitle>
                                <DialogDescription>
                                    It starts in Backlog. Nothing runs until you
                                    move it to Specify.
                                </DialogDescription>
                            </DialogHeader>

                            <TicketFormFields
                                options={options}
                                values={defaults}
                                errors={errors}
                            />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="create-ticket-submit"
                                >
                                    Add to Backlog
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
