import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { FaLock, FaRotateLeft, FaTriangleExclamation } from 'react-icons/fa6';
import Input from '@/reviactyl/elements/Input';
import Select from '@/reviactyl/elements/Select';
import Switch from '@/reviactyl/elements/Switch';
import { Button } from '@/reviactyl/elements/button/index';
import { PropertyDefinition } from '@/api/server/properties/properties';

interface Props {
    definition: PropertyDefinition;
    value: string;
    onChange: (key: string, value: string) => void;
}

const PropertyField = ({ definition, value, onChange }: Props) => {
    const { t } = useTranslation('server/properties');
    const { key, type, locked } = definition;

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
                <Select value={value} disabled={locked} onChange={(e) => set(e.currentTarget.value)}>
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
                type={type === 'int' ? 'number' : definition.sensitive ? 'password' : 'text'}
                value={value}
                disabled={locked}
                min={definition.min ?? undefined}
                max={definition.max ?? undefined}
                onChange={(e) => set(e.currentTarget.value)}
            />
        );
    };

    return (
        <div css={tw`py-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-6`}>
            <div css={tw`sm:w-1/2 min-w-0`}>
                <div css={tw`flex items-center gap-2 flex-wrap`}>
                    <span css={tw`text-sm text-gray-100`}>{definition.label}</span>
                    {locked && <FaLock css={tw`text-xs text-gray-500`} title={t('locked')} />}
                    {definition.warn && <FaTriangleExclamation css={tw`text-xs text-amber-400`} title={t('warn')} />}
                </div>
                <code css={tw`block mt-0.5 text-xs font-mono text-gray-500 break-all`}>{key}</code>
                {definition.description && <p css={tw`mt-1 text-xs text-gray-400`}>{definition.description}</p>}
                {locked && <p css={tw`mt-1 text-xs text-gray-500 italic`}>{t('locked')}</p>}
            </div>
            <div css={tw`sm:w-1/2 flex items-center gap-2`}>
                <div css={tw`flex-1 min-w-0`}>{control()}</div>
                {!locked && value !== definition.default && (
                    <Button.Text
                        type={'button'}
                        size={Button.Sizes.Small}
                        shape={Button.Shapes.IconSquare}
                        title={t('reset')}
                        aria-label={t('reset')}
                        onClick={() => set(definition.default)}
                    >
                        <FaRotateLeft />
                    </Button.Text>
                )}
            </div>
        </div>
    );
};

export default PropertyField;
