import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent, DragStartEvent } from '@dnd-kit/core';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import TicketCard from '@/components/board/ticket-card';
import { cn } from '@/lib/utils';
import { move } from '@/routes/projects/tickets';
import type { BoardColumn, TicketCard as TicketCardType } from '@/types';

type Props = {
    columns: BoardColumn[];
    tickets: TicketCardType[];
    routeArgs: { current_team: string; project: string };
    canWork: boolean;
    onOpen: (ticket: TicketCardType) => void;
    backlogAction?: ReactNode;
};

const COLUMN_HINTS: Partial<Record<string, string>> = {
    backlog: 'New features wait here. Drag one to Specify to start.',
    review: 'Implemented. Check the branch, then move to Done.',
};

/**
 * The Kanban board. Dropping a card on a column asks the server to move it; the server
 * decides whether the move is allowed and starts the phase's run.
 */
export default function Board({
    columns,
    tickets,
    routeArgs,
    canWork,
    onOpen,
    backlogAction,
}: Props) {
    const [activeId, setActiveId] = useState<number | null>(null);
    // Show the card in its new column right away; the server's answer replaces this.
    const [pending, setPending] = useState<Record<number, string>>({});

    const sensors = useSensors(
        // A small distance so a click still opens the card.
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor),
    );

    const stageOf = (ticket: TicketCardType) =>
        pending[ticket.id] ?? ticket.stage;
    const active = tickets.find((ticket) => ticket.id === activeId) ?? null;

    const onDragStart = (event: DragStartEvent) => {
        setActiveId(Number(event.active.id));
    };

    const onDragEnd = (event: DragEndEvent) => {
        setActiveId(null);

        const ticket = tickets.find(
            (item) => item.id === Number(event.active.id),
        );
        const target = event.over?.id ? String(event.over.id) : null;

        if (!ticket || !target || target === ticket.stage) {
            return;
        }

        setPending((current) => ({ ...current, [ticket.id]: target }));

        router.post(
            move.url({ ...routeArgs, ticket: ticket.number }),
            { stage: target },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    toast.error(
                        errors.ticket ??
                            errors.stage ??
                            'That move is not allowed.',
                    );
                },
                onFinish: () => {
                    setPending((current) => {
                        const next = { ...current };
                        delete next[ticket.id];

                        return next;
                    });
                },
            },
        );
    };

    return (
        <DndContext
            sensors={sensors}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            onDragCancel={() => setActiveId(null)}
        >
            <div className="-mx-4 overflow-x-auto px-4 pb-4">
                <div className="flex min-w-max gap-3">
                    {columns.map((column) => {
                        const cards = tickets.filter(
                            (ticket) => stageOf(ticket) === column.value,
                        );

                        return (
                            <Column
                                key={column.value}
                                column={column}
                                count={cards.length}
                                hint={COLUMN_HINTS[column.value]}
                                action={
                                    column.value === 'backlog'
                                        ? backlogAction
                                        : null
                                }
                            >
                                {cards.map((ticket) => (
                                    <DraggableCard
                                        key={ticket.id}
                                        ticket={ticket}
                                        disabled={
                                            !canWork ||
                                            ticket.status === 'running'
                                        }
                                        onOpen={() => onOpen(ticket)}
                                    />
                                ))}
                            </Column>
                        );
                    })}
                </div>
            </div>

            <DragOverlay>
                {active ? <TicketCard ticket={active} dragging /> : null}
            </DragOverlay>
        </DndContext>
    );
}

function Column({
    column,
    count,
    hint,
    action,
    children,
}: {
    column: BoardColumn;
    count: number;
    hint?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: column.value });

    return (
        <section
            ref={setNodeRef}
            aria-label={column.label}
            data-test={`column-${column.value}`}
            className={cn(
                'flex w-64 shrink-0 flex-col gap-2 rounded-xl border bg-muted/30 p-2 transition-colors',
                isOver && 'border-primary/50 bg-accent',
            )}
        >
            <header className="flex items-center justify-between px-1 pt-1">
                <h3 className="flex items-center gap-1.5 text-sm font-medium">
                    {column.label}
                    {column.optional ? (
                        <span className="text-xs font-normal text-muted-foreground">
                            optional
                        </span>
                    ) : null}
                </h3>
                <span className="text-xs text-muted-foreground">{count}</span>
            </header>

            {action}

            <div className="flex min-h-24 flex-col gap-2">
                {children}
                {count === 0 && hint ? (
                    <p className="px-1 py-2 text-xs text-muted-foreground">
                        {hint}
                    </p>
                ) : null}
            </div>
        </section>
    );
}

function DraggableCard({
    ticket,
    disabled,
    onOpen,
}: {
    ticket: TicketCardType;
    disabled: boolean;
    onOpen: () => void;
}) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: ticket.id,
        disabled,
    });

    return (
        <div
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            className={cn(isDragging && 'opacity-40')}
        >
            <TicketCard ticket={ticket} onOpen={onOpen} />
        </div>
    );
}
