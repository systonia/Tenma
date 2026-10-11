// VS Code only talks to debuggers it knows: this registers the type "tenma" and points it at the
// DAP server of tenma --inspect, which does all the debugging.
const vscode = require('vscode');

exports.activate = (context) => {
    context.subscriptions.push(vscode.debug.registerDebugAdapterDescriptorFactory('tenma', {
        createDebugAdapterDescriptor: (session) =>
            new vscode.DebugAdapterServer(session.configuration.port ?? 9229, session.configuration.host ?? '127.0.0.1'),
    }));
};
