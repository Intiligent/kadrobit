/**
 * Whether a drag carries data of this type: 'Files', DRAG_ASSETS, DRAG_FOLDER.
 * Only the types can be read before the drop, not the data.
 */
export const dragHasType = (event, type) => Array.from(event.dataTransfer?.types ?? []).includes(type);
