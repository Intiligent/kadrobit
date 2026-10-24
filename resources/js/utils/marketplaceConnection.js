/**
 * What the status column shows for a connected account (MarketplaceConnectionResource):
 * a failed check on an active account shows the failure, not a healthy "Connected".
 */
export function connectionState(account, checking = new Set()) {
    if (checking.has(account.id)) {
        return 'checking';
    }

    if (account.status === 'active' && account.last_error === 'connect') {
        return 'unreachable';
    }

    if (account.status === 'active' && account.last_error) {
        return 'check_failed';
    }

    return account.status;
}

/**
 * Starting name for a new account: the marketplace name, then "Shutterstock 2", "Shutterstock 3"…
 * skipping names already taken on that marketplace.
 */
export function nextAccountLabel(name, takenLabels) {
    const taken = new Set(takenLabels);

    if (!taken.has(name)) {
        return name;
    }

    let number = 2;

    while (taken.has(`${name} ${number}`)) {
        number += 1;
    }

    return `${name} ${number}`;
}
