import { EajLockup } from '@/components/brand/eaj-lockup';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-6">
                        {/* Stacked lockup on login, splash and empty states (Plan §7.1). */}
                        <EajLockup orientation="stacked" />

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <span className="brand-rule mx-auto" aria-hidden="true" />
                            <p className="text-muted-foreground pt-1 text-center text-sm">{description}</p>
                        </div>
                    </div>
                    <div className="bg-card border-border rounded-lg border p-6 shadow-sm">{children}</div>
                    <p className="eyebrow text-center">EAJ Web Development Services</p>
                </div>
            </div>
        </div>
    );
}
