import { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import Modal from '@/reviactyl/elements/Modal';
import Button from '@/reviactyl/elements/Button';
import Switch from '@/reviactyl/elements/Switch';
import FlashMessageRender from '@/components/FlashMessageRender';
import { StartupPart } from '@/api/server/types';

interface Props {
    visible: boolean;
    onDismissed: () => void;
    parts: StartupPart[];
    onSave: (parts: { part_id: number; enabled: boolean }[]) => Promise<void>;
}

export default ({ visible, onDismissed, parts, onSave }: Props) => {
    const [enabled, setEnabled] = useState<Record<number, boolean>>({});
    const [saving, setSaving] = useState(false);

    // Re-sync local state from the server response every time the modal opens.
    useEffect(() => {
        if (visible) {
            setEnabled(Object.fromEntries(parts.map((p) => [p.id, p.userEnabled])));
        }
    }, [visible, parts]);

    const groups = useMemo(() => {
        const map = new Map<string, StartupPart[]>();

        for (const part of parts) {
            const key = part.groupName || '';
            map.set(key, [...(map.get(key) || []), part]);
        }

        return [...map.entries()];
    }, [parts]);

    const save = async () => {
        setSaving(true);

        try {
            await onSave(
                parts.map((p) => ({ part_id: p.id, enabled: p.required || (enabled[p.id] ?? p.userEnabled) }))
            );
            onDismissed();
        } catch {
            // Error is surfaced by the caller through the flash system.
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal visible={visible} onDismissed={onDismissed}>
            <FlashMessageRender byKey={'startup:parts'} css={tw`mb-4`} />
            <h3 css={tw`text-2xl mb-4`}>Startup Parts</h3>
            <p css={tw`text-sm text-gray-400 mb-4`}>
                Toggle optional flags added to the startup command. Required parts cannot be disabled.
            </p>
            <div css={tw`space-y-6 max-h-[60vh] overflow-y-auto pr-1`}>
                {groups.map(([group, groupParts]) => (
                    <div key={group || 'default'}>
                        {group && <p css={tw`text-xs uppercase text-gray-500 font-semibold mb-2`}>{group}</p>}
                        <div css={tw`space-y-2`}>
                            {groupParts.map((part) => (
                                <div
                                    key={part.id}
                                    css={tw`flex items-center gap-3 p-3 rounded-ui border border-gray-700 bg-gray-800`}
                                >
                                    <div css={tw`flex-1 min-w-0`}>
                                        <div css={tw`flex items-center gap-2`}>
                                            <span css={tw`text-sm font-medium text-gray-200`}>{part.name}</span>
                                            {part.required && (
                                                <span
                                                    css={tw`text-xs bg-reviactyl/20 text-reviactyl px-1.5 py-0.5 rounded`}
                                                >
                                                    Required
                                                </span>
                                            )}
                                        </div>
                                        <p css={tw`text-xs font-mono text-gray-400 truncate`}>{part.value}</p>
                                        {part.description && (
                                            <p css={tw`text-xs text-gray-500 mt-0.5`}>{part.description}</p>
                                        )}
                                    </div>
                                    <Switch
                                        name={`part-${part.id}`}
                                        readOnly={part.required}
                                        checked={part.required || (enabled[part.id] ?? part.userEnabled)}
                                        onChange={() =>
                                            !part.required &&
                                            setEnabled((prev) => ({
                                                ...prev,
                                                [part.id]: !(prev[part.id] ?? part.userEnabled),
                                            }))
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
            <div css={tw`flex justify-end gap-2 mt-6`}>
                <Button onClick={save} disabled={saving}>
                    {saving ? 'Saving…' : 'Save'}
                </Button>
            </div>
        </Modal>
    );
};
