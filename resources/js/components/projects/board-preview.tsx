const COLUMNS = [
    'Backlog',
    'Specify',
    'Clarify',
    'Plan',
    'Tasks',
    'Analyze',
    'Implement',
    'Review',
    'Done',
];

/** The Spec Kit phase columns, shown empty until the board arrives in Phase 4. */
export default function BoardPreview() {
    return (
        <div className="-mx-4 overflow-x-auto px-4 pb-2">
            <div className="flex min-w-max gap-3">
                {COLUMNS.map((column) => (
                    <div
                        key={column}
                        className="flex h-40 w-40 flex-col rounded-lg border border-dashed bg-muted/30 p-3"
                    >
                        <span className="text-xs font-medium text-muted-foreground">
                            {column}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
