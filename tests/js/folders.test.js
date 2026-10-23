import { describe, expect, it } from 'vitest';
import { buildFolderTree, canMoveInto, dropPlacement, folderPath, subtreeIds } from '../../resources/js/utils/folders';

const folders = [
    { id: 'travel', parent_id: null, name: 'Travel 2026' },
    { id: 'iceland', parent_id: 'travel', name: 'Iceland' },
    { id: 'glaciers', parent_id: 'iceland', name: 'Glaciers' },
    { id: 'food', parent_id: null, name: 'Food' },
];

const asset = (folder_id) => ({ folder_id });

describe('buildFolderTree', () => {
    it('nests folders and counts works including subfolders', () => {
        const tree = buildFolderTree(folders, [asset('glaciers'), asset('glaciers'), asset('iceland'), asset(null)]);

        expect(tree.map((node) => [node.name, node.count])).toEqual([['Travel 2026', 3], ['Food', 0]]);
        expect(tree[0].children[0]).toMatchObject({ name: 'Iceland', count: 3 });
        expect(tree[0].children[0].children[0]).toMatchObject({ name: 'Glaciers', count: 2, children: [] });
    });

    it('keeps a folder whose parent is missing at the top level', () => {
        const tree = buildFolderTree([{ id: 'orphan', parent_id: 'gone', name: 'Orphan' }], []);

        expect(tree.map((node) => node.name)).toEqual(['Orphan']);
    });
});

describe('subtreeIds', () => {
    it('returns the folder and all its descendants', () => {
        expect([...subtreeIds(folders, 'travel')].sort()).toEqual(['glaciers', 'iceland', 'travel']);
        expect([...subtreeIds(folders, 'food')]).toEqual(['food']);
    });
});

describe('folderPath', () => {
    it('lists names from the top level down', () => {
        expect(folderPath(folders, 'glaciers')).toEqual(['Travel 2026', 'Iceland', 'Glaciers']);
        expect(folderPath(folders, 'all')).toEqual([]);
    });
});

describe('canMoveInto', () => {
    it('refuses the folder itself and its subfolders', () => {
        expect(canMoveInto(folders, 'travel', 'travel')).toBe(false);
        expect(canMoveInto(folders, 'travel', 'glaciers')).toBe(false);
        expect(canMoveInto(folders, 'glaciers', 'travel')).toBe(true);
        expect(canMoveInto(folders, 'travel', null)).toBe(true);
    });
});

describe('dropPlacement', () => {
    // Top level in tree order: travel, food, drinks.
    const list = [...folders, { id: 'drinks', parent_id: null, name: 'Drinks' }];

    it('puts a folder last inside the target', () => {
        expect(dropPlacement(list, 'food', 'iceland', 'inside')).toEqual({ parent_id: 'iceland', position: 1 });
    });

    it('puts a folder before or after a sibling, counting without itself', () => {
        expect(dropPlacement(list, 'drinks', 'travel', 'before')).toEqual({ parent_id: null, position: 0 });
        expect(dropPlacement(list, 'travel', 'food', 'after')).toEqual({ parent_id: null, position: 1 });
        expect(dropPlacement(list, 'glaciers', 'food', 'after')).toEqual({ parent_id: null, position: 2 });
    });

    it('refuses drops onto itself and into its own subtree', () => {
        expect(dropPlacement(list, 'travel', 'travel', 'inside')).toBeNull();
        expect(dropPlacement(list, 'travel', 'iceland', 'inside')).toBeNull();
        // Next to its own subfolder is inside itself too.
        expect(dropPlacement(list, 'travel', 'glaciers', 'before')).toBeNull();
        expect(dropPlacement(list, 'travel', 'missing', 'inside')).toBeNull();
    });
});
