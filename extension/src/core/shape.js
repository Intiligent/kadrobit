/**
 * Checks answers of a stock site's internal API before the adapter relies on them. The sites change these APIs
 * without notice; a changed answer stops the adapter with a clear error instead of sending wrong data.
 *
 * A schema is a type name ('string', 'number', 'boolean', 'object', 'any'), an array with one item schema,
 * or an object of field schemas. A field name ending in "?" may be missing or null.
 *
 *     expectShape(json, { data: [{ id: 'string', uploadedFilename: 'string', categories: ['string'] }] }, 'media/list');
 */
export class ShapeError extends Error {
    constructor(source, path, expected) {
        super(`The site changed: ${source} has no ${expected} at ${path || 'the top'}`);
        this.name = 'ShapeError';
        this.source = source;
        this.path = path;
    }
}

export function expectShape(value, schema, source, path = '') {
    if (Array.isArray(schema)) {
        if (!Array.isArray(value)) {
            throw new ShapeError(source, path, 'list');
        }

        value.forEach((item, index) => expectShape(item, schema[0], source, `${path}[${index}]`));

        return value;
    }

    if (typeof schema === 'object') {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            throw new ShapeError(source, path, 'object');
        }

        for (const [key, fieldSchema] of Object.entries(schema)) {
            const optional = key.endsWith('?');
            const name = optional ? key.slice(0, -1) : key;
            const field = value[name];

            if (optional && (field === undefined || field === null)) {
                continue;
            }

            expectShape(field, fieldSchema, source, path ? `${path}.${name}` : name);
        }

        return value;
    }

    if (schema === 'any' ? value === undefined : typeof value !== schema || value === null) {
        throw new ShapeError(source, path, schema);
    }

    return value;
}
