/**
 * Folder tree helpers for the library. Folders come as a flat list: [{ id, parent_id, name }].
 */

/**
 * Nested tree for the explorer: [{ id, name, count, children }]. A folder's count includes its subfolders.
 */
export function buildFolderTree(folders, assets) {
    const own = new Map();

    for (const asset of assets) {
        if (asset.folder_id) {
            own.set(asset.folder_id, (own.get(asset.folder_id) ?? 0) + 1);
        }
    }

    const nodes = new Map(folders.map((f) => [f.id, { id: f.id, name: f.name, count: 0, children: [] }]));
    const roots = [];

    for (const folder of folders) {
        const node = nodes.get(folder.id);
        const parent = folder.parent_id && nodes.get(folder.parent_id);
        (parent ? parent.children : roots).push(node);
    }

    const total = (node) => {
        node.count = (own.get(node.id) ?? 0) + node.children.reduce((sum, child) => sum + total(child), 0);

        return node.count;
    };

    roots.forEach(total);

    return roots;
}

/**
 * Ids of a folder and all its subfolders.
 */
export function subtreeIds(folders, id) {
    const ids = new Set([id]);
    let added = true;

    while (added) {
        added = false;

        for (const folder of folders) {
            if (folder.parent_id && ids.has(folder.parent_id) && !ids.has(folder.id)) {
                ids.add(folder.id);
                added = true;
            }
        }
    }

    return ids;
}

/**
 * Names from the top-level folder down to this one; empty for anything that is not a folder.
 */
export function folderPath(folders, id) {
    const byId = new Map(folders.map((f) => [f.id, f]));
    const names = [];

    for (let folder = byId.get(id); folder; folder = byId.get(folder.parent_id)) {
        names.unshift(folder.name);
    }

    return names;
}

/**
 * Whether a folder can go into the target: not into itself or one of its own subfolders. Null target is the top level.
 */
export function canMoveInto(folders, id, targetId) {
    return !targetId || !subtreeIds(folders, id).has(targetId);
}

/**
 * Where a folder dropped on another one goes: `inside` the target (last), or `before`/`after` it among its siblings.
 * Returns { parent_id, position } for PATCH /api/folders/{id}, or null when the drop is not allowed.
 * Positions are indexes among the siblings without the moved folder, as the server counts them.
 */
export function dropPlacement(folders, id, targetId, zone) {
    const target = folders.find((f) => f.id === targetId);

    if (!target || id === targetId) {
        return null;
    }

    const parentId = zone === 'inside' ? target.id : target.parent_id ?? null;

    if (!canMoveInto(folders, id, parentId)) {
        return null;
    }

    const siblings = folders.filter((f) => (f.parent_id ?? null) === parentId && f.id !== id);

    if (zone === 'inside') {
        return { parent_id: parentId, position: siblings.length };
    }

    const index = siblings.findIndex((f) => f.id === targetId);

    return { parent_id: parentId, position: zone === 'before' ? index : index + 1 };
}
