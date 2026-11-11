import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { cn } from '@/lib/utils';

/** Renders Spec Kit documents and agent messages. Raw HTML in the source is not rendered. */
export default function Markdown({
    children,
    className,
}: {
    children: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'space-y-3 text-sm leading-relaxed break-words',
                className,
            )}
        >
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                components={{
                    h1: (props) => (
                        <h1
                            className="mt-4 text-lg font-semibold first:mt-0"
                            {...props}
                        />
                    ),
                    h2: (props) => (
                        <h2
                            className="mt-4 text-base font-semibold first:mt-0"
                            {...props}
                        />
                    ),
                    h3: (props) => (
                        <h3
                            className="mt-3 font-semibold first:mt-0"
                            {...props}
                        />
                    ),
                    h4: (props) => (
                        <h4 className="mt-3 font-medium" {...props} />
                    ),
                    ul: (props) => (
                        <ul className="list-disc space-y-1 pl-5" {...props} />
                    ),
                    ol: (props) => (
                        <ol
                            className="list-decimal space-y-1 pl-5"
                            {...props}
                        />
                    ),
                    a: (props) => (
                        <a
                            className="underline underline-offset-4"
                            target="_blank"
                            rel="noreferrer"
                            {...props}
                        />
                    ),
                    blockquote: (props) => (
                        <blockquote
                            className="border-l-2 pl-3 text-muted-foreground"
                            {...props}
                        />
                    ),
                    code: ({ className: codeClass, ...props }) => (
                        <code
                            className={cn(
                                'rounded bg-muted px-1 py-0.5 font-mono text-xs',
                                codeClass,
                            )}
                            {...props}
                        />
                    ),
                    pre: (props) => (
                        <pre
                            className="overflow-x-auto rounded-md border bg-muted/50 p-3 text-xs [&_code]:bg-transparent [&_code]:p-0"
                            {...props}
                        />
                    ),
                    table: (props) => (
                        <div className="overflow-x-auto">
                            <table
                                className="w-full border-collapse text-xs"
                                {...props}
                            />
                        </div>
                    ),
                    th: (props) => (
                        <th
                            className="border px-2 py-1 text-left font-medium"
                            {...props}
                        />
                    ),
                    td: (props) => (
                        <td className="border px-2 py-1 align-top" {...props} />
                    ),
                    hr: () => <hr className="my-4" />,
                    input: (props) => (
                        <input
                            className="mr-1.5 align-middle"
                            disabled
                            {...props}
                        />
                    ),
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}
