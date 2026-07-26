import { PropertyDefinition } from '@/api/server/properties/properties';

export interface PropertyError {
    key: string;
    params?: Record<string, unknown>;
}

/**
 * Mirrors the checks in ServerPropertiesService::cast() so a bad value is
 * caught while the user is typing rather than after a round trip. Returns a
 * translation key rather than a string; the field component translates it.
 */
export const validateProperty = (definition: PropertyDefinition, value: string): PropertyError | null => {
    if (definition.locked || definition.type !== 'int') {
        return null;
    }

    if (!/^-?\d+$/.test(value.trim())) {
        return { key: 'error_number' };
    }

    const number = Number(value);

    if (definition.min !== null && number < definition.min) {
        return { key: 'error_min', params: { min: definition.min } };
    }

    if (definition.max !== null && number > definition.max) {
        return { key: 'error_max', params: { max: definition.max } };
    }

    return null;
};
