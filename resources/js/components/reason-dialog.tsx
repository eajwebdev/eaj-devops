import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ReasonDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    label: string;
    confirm: string;
    required?: boolean;
    destructive?: boolean;
    processing?: boolean;
    onConfirm: (reason: string) => void;
}

/** Asks for the "why" that a hold, a change request or a cancellation needs (Plan §95). */
export function ReasonDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    confirm,
    required = true,
    destructive = false,
    processing = false,
    onConfirm,
}: ReasonDialogProps) {
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (open) setReason('');
    }, [open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!required || reason.trim()) onConfirm(reason.trim());
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">{label}</Label>
                        <textarea
                            id="reason"
                            autoFocus
                            required={required}
                            rows={3}
                            maxLength={2000}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={processing || (required && !reason.trim())}>
                            {processing && <LoaderCircle className="animate-spin" />}
                            {confirm}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
