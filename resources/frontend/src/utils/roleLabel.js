const labels = {
    requester: 'Requester',
    agent: 'Agent',
    dispatcher: 'Dispatcher',
    inventory_manager: 'Inventory Manager',
    admin: 'Admin',
    superadmin: 'Superadmin',
}

export function roleLabel(role) {
    return labels[role] || String(role || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}
