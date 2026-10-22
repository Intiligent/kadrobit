import { beforeEach, describe, expect, it, vi } from 'vitest';

const closeHandlers = [];
let resolveConfirm;

vi.mock('element-plus', () => ({
    ElNotification: vi.fn((options) => closeHandlers.push(options.onClose)),
    ElMessageBox: {
        confirm: vi.fn(() => new Promise((resolve) => {
            resolveConfirm = resolve;
        })),
    },
}));

const { default: notify } = await import('@/core/notify');
const { ElMessageBox, ElNotification } = await import('element-plus');

beforeEach(() => {
    vi.clearAllMocks();
    closeHandlers.length = 0;
});

describe('notify', () => {
    it('shows one notification per key until it is closed', () => {
        notify.error({ key: 'network', message: 'Offline' });
        notify.error({ key: 'network', message: 'Offline' });

        expect(ElNotification).toHaveBeenCalledOnce();

        closeHandlers[0]();
        notify.error({ key: 'network', message: 'Offline' });

        expect(ElNotification).toHaveBeenCalledTimes(2);
    });

    it('does not deduplicate notifications without a key', () => {
        notify.warning({ message: 'A' });
        notify.warning({ message: 'A' });

        expect(ElNotification).toHaveBeenCalledTimes(2);
    });

    it('opens one confirm dialog per key and resolves the duplicate to false', async () => {
        const first = notify.confirm({ key: 'unauthenticated', message: 'Log in?' });
        const second = notify.confirm({ key: 'unauthenticated', message: 'Log in?' });

        await expect(second).resolves.toBe(false);
        expect(ElMessageBox.confirm).toHaveBeenCalledOnce();

        resolveConfirm();
        await expect(first).resolves.toBe(true);

        notify.confirm({ key: 'unauthenticated', message: 'Log in?' });
        expect(ElMessageBox.confirm).toHaveBeenCalledTimes(2);
    });
});
