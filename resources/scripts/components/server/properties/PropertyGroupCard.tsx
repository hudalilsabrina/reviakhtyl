import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { FaChevronDown, FaChevronRight } from 'react-icons/fa6';
import Card from '@/reviactyl/ui/Card';
import { PropertyGroup } from '@/api/server/properties/properties';
import PropertyField from './PropertyField';

interface Props {
    group: PropertyGroup;
    values: Record<string, string>;
    changed: Record<string, string>;
    expanded: boolean;
    onToggle: (id: string) => void;
    onChange: (key: string, value: string) => void;
}

const PropertyGroupCard = ({ group, values, changed, expanded, onToggle, onChange }: Props) => {
    const { t } = useTranslation('server/properties');
    const changedCount = group.properties.filter((p) => changed[p.key] !== undefined).length;

    return (
        <Card css={tw`!p-0`}>
            <button
                type={'button'}
                aria-expanded={expanded}
                aria-controls={`group-${group.id}`}
                onClick={() => onToggle(group.id)}
                css={tw`w-full flex items-center gap-3 px-5 py-4 text-left hover:bg-gray-800/40 transition-colors duration-150 rounded-ui`}
            >
                <span css={tw`text-gray-500 text-xs flex-none`} aria-hidden={'true'}>
                    {expanded ? <FaChevronDown /> : <FaChevronRight />}
                </span>
                <h2 css={tw`flex-1 text-sm font-semibold uppercase tracking-wider text-gray-300`}>{group.label}</h2>
                {changedCount > 0 && (
                    <span
                        css={tw`px-2 py-0.5 text-xs rounded-ui bg-cyan-500/20 text-cyan-300 border border-cyan-500/40`}
                    >
                        {t('group_changed', { count: changedCount })}
                    </span>
                )}
                <span css={tw`text-xs text-gray-500`}>{t('group_count', { count: group.properties.length })}</span>
            </button>
            {expanded && (
                <div id={`group-${group.id}`} css={tw`px-5 pb-1 divide-y divide-gray-800`}>
                    {group.properties.map((definition) => (
                        <PropertyField
                            key={definition.key}
                            definition={definition}
                            value={values[definition.key] ?? definition.default}
                            changed={changed[definition.key] !== undefined}
                            onChange={onChange}
                        />
                    ))}
                </div>
            )}
        </Card>
    );
};

export default PropertyGroupCard;
