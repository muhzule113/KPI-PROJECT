import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

function FieldError({ message }) {
    return message ? <p className="mt-1.5 text-xs font-medium text-destructive">{message}</p> : null;
}

export default function AdminForm({ fields, options, form }) {
    return (
        <div className="grid gap-5 sm:grid-cols-2">
            {fields.map((field) => {
                const value = form.data[field.name] ?? '';
                const error = form.errors[field.name];
                const fieldOptions = options[field.name] ?? [];
                const commonProps = {
                    id: field.name,
                    name: field.name,
                    required: field.required,
                    disabled: field.readOnly,
                    readOnly: field.readOnly,
                    'aria-invalid': Boolean(error),
                    'aria-describedby': error ? `${field.name}-error` : undefined,
                };

                return (
                    <div key={field.name} className={cn(field.type === 'textarea' && 'sm:col-span-2', field.type === 'checkbox' && 'sm:col-span-2')}>
                        {field.type === 'checkbox-list' ? (
                            <fieldset>
                                <legend className="mb-2 block text-sm font-medium text-foreground">{field.label}{field.required && <span className="ml-1 text-destructive" aria-hidden="true">*</span>}</legend>
                                <div className="grid gap-2 rounded-xl border border-border bg-muted/20 p-3 sm:grid-cols-2">
                                    {fieldOptions.map((option) => {
                                        const selected = Array.isArray(value) && value.map(String).includes(String(option.value));

                                        return (
                                            <label key={option.value} className="flex items-center gap-2 text-sm text-foreground">
                                                <input
                                                    type="checkbox"
                                                    name={`${field.name}[]`}
                                                    value={option.value}
                                                    checked={selected}
                                                    disabled={field.readOnly}
                                                    onChange={(event) => {
                                                        const next = new Set((Array.isArray(value) ? value : []).map(String));
                                                        event.target.checked ? next.add(String(option.value)) : next.delete(String(option.value));
                                                        form.setData(field.name, Array.from(next));
                                                    }}
                                                    className="size-4 rounded border-input text-primary accent-primary focus:ring-primary"
                                                />
                                                {option.label}
                                            </label>
                                        );
                                    })}
                                </div>
                            </fieldset>
                        ) : field.type === 'checkbox' ? (
                            <label htmlFor={field.name} className="flex min-h-10 items-center gap-3 rounded-xl border border-border bg-muted/20 px-3 text-sm font-medium text-foreground">
                                <input
                                    {...commonProps}
                                    type="checkbox"
                                    checked={Boolean(value)}
                                    onChange={(event) => form.setData(field.name, event.target.checked)}
                                    className="size-4 rounded border-input text-primary accent-primary focus:ring-primary"
                                />
                                {field.label}
                            </label>
                        ) : (
                            <>
                                <label htmlFor={field.name} className="mb-2 block text-sm font-medium text-foreground">
                                    {field.label}{field.required && <span className="ml-1 text-destructive" aria-hidden="true">*</span>}
                                </label>
                                {field.type === 'select' ? (
                                    <select
                                        {...commonProps}
                                        value={value}
                                        onChange={(event) => form.setData(field.name, event.target.value || null)}
                                        className="flex h-9 w-full rounded-lg border border-input bg-background px-2.5 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {!field.required && <option value="">Pilih {field.label.toLowerCase()}</option>}
                                        {fieldOptions.map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </select>
                                ) : field.type === 'textarea' ? (
                                    <textarea
                                        {...commonProps}
                                        value={value}
                                        placeholder={field.placeholder}
                                        rows={field.rows ?? 4}
                                        onChange={(event) => form.setData(field.name, event.target.value)}
                                        className="flex min-h-24 w-full resize-y rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    />
                                ) : (
                                    <Input
                                        {...commonProps}
                                        type={field.type ?? 'text'}
                                        value={value}
                                        placeholder={field.placeholder}
                                        onChange={(event) => form.setData(field.name, event.target.value)}
                                    />
                                )}
                            </>
                        )}
                        <div id={error ? `${field.name}-error` : undefined}>
                            <FieldError message={error} />
                        </div>
                        {field.help && !error && <p className="mt-1.5 text-xs text-muted-foreground">{field.help}</p>}
                    </div>
                );
            })}
        </div>
    );
}
