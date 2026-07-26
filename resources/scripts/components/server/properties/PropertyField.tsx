import { useMemo, useState } from 'react';
import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { FaEye, FaEyeSlash, FaLock, FaRotateLeft, FaTriangleExclamation } from 'react-icons/fa6';
import Input from '@/reviactyl/elements/Input';
import Select from '@/reviactyl/elements/Select';
import Switch from '@/reviactyl/elements/Switch';
import { Button } from '@/reviactyl/elements/button/index';
import { PropertyDefinition } from '@/api/server/properties/properties';
import { validateProperty } from './validation';

interface Props {
    definition: PropertyDefinition;
    value: string;
    changed: boolean;
    onChange: (key: string, value: string) => void;
}

const PropertyField = ({ definition, value, changed, onChange }: Props) => {
    const { t } = useTranslation('server/properties');
    const [revealed, setRevealed] = useState(false);
    const { key, type, locked } = definition;

    const error = useMemo(() => validateProperty(definition, value), [definition, value]);
    const describedBy = error ? `${key}-error` : undefined;

    const set = (next: string) => {
        if (!locked) onChange(key, next);
    };

    const control = () => {
        if (type === 'bool') {
            return (
                <Switch
                    name={key}
                    readOnly={locked}
                    checked={value === 'true'}
                    onChange={(e) => set(e.currentTarget.checked ? 'true' : 'false')}
                />
            );
        }

        if (type === 'enum') {
            return (
                <Select
                    id={key}
                    value={value}
                    disabled={locked}
                    aria-label={definition.label}
                    onChange={(e) => set(e.currentTarget.value)}
                >
                    {(definition.options || []).map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                    {/* A file can hold a value the current Minecraft version no longer offers. */}
                    {!(definition.options || []).includes(value) && <option value={value}>{value}</option>}
                </Select>
            );
        }

        return (
            <Input
                id={key}
                type={type === 'int' ? 'number' : definition.sensitive && !revealed ? 'password' : 'text'}
                value={value}
                disabled={locked}
                min={definition.min ?? undefined}
                max={definition.max ?? undefined}
                aria-label={definition.label}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                $hasError={!!error}
                onChange={(e) => set(e.currentTarget.value)}
            />
        );
    };

    return (
        <div css={tw`py-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-6`}>
            <div css={tw`sm:w-1/2 min-w-0`}>
                <label htmlFor={type === 'bool' ? undefined : key} css={tw`flex items-center gap-2 flex-wrap`}>
                    <span css={tw`text-sm text-gray-100`}>{definition.label}</span>
                    {changed && <span css={tw`w-1.5 h-1.5 rounded-full bg-cyan-400`} aria-hidden={'true'} />}
                </label>
                <code css={tw`block mt-0.5 text-xs font-mono text-gray-500 break-all`}>{key}</code>
                {definition.description && <p css={tw`mt-1 text-xs text-gray-400`}>{definition.description}</p>}
                {locked && (
                    <p css={tw`mt-1 flex items-start gap-1.5 text-xs text-gray-500`}>
                        <FaLock css={tw`mt-0.5 flex-none`} aria-hidden={'true'} />
                        <span>{t('locked')}</span>
                    </p>
                )}
                {definition.warn && (
                    <p css={tw`mt-1 flex items-start gap-1.5 text-xs text-amber-400/90`}>
                        <FaTriangleExclamation css={tw`mt-0.5 flex-none`} aria-hidden={'true'} />
                        <span>{t('warn')}</span>
                    </p>
                )}
            </div>
            <div css={tw`sm:w-1/2 min-w-0`}>
                <div css={tw`flex items-center gap-2`}>
                    <div css={tw`flex-1 min-w-0`}>{control()}</div>
                    {definition.sensitive && !locked && (
                        <Button.Text
                            type={'button'}
                            size={Button.Sizes.Small}
                            shape={Button.Shapes.IconSquare}
                            aria-label={revealed ? t('hide') : t('reveal')}
                            onClick={() => setRevealed((current) => !current)}
                        >
                            {revealed ? <FaEyeSlash /> : <FaEye />}
                        </Button.Text>
                    )}
                    {/* Kept mounted so the input does not resize as a value diverges from its default. */}
                    <Button.Text
                        type={'button'}
                        size={Button.Sizes.Small}
                        shape={Button.Shapes.IconSquare}
                        aria-label={t('reset')}
                        title={t('reset')}
                        disabled={locked || value === definition.default}
                        css={locked || value === definition.default ? tw`invisible` : undefined}
                        onClick={() => set(definition.default)}
                    >
                        <FaRotateLeft />
                    </Button.Text>
                </div>
                {error && (
                    <p id={describedBy} css={tw`mt-1.5 text-xs text-red-400`}>
                        {t(error.key, error.params)}
                    </p>
                )}
            </div>
        </div>
    );
};

export default PropertyField;
