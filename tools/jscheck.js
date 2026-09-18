function run() {
    var args = $.NSProcessInfo.processInfo.arguments;
    if (args.count < 2) return 'NO_ARGS';
    var path = args.objectAtIndex(1).js;
    var text = $.NSString.stringWithContentsOfFileEncodingError(path, $.NSUTF8StringEncoding, null);
    if (text.isNil()) return 'READ_ERROR: ' + path;
    try {
        new Function(text.js);
        return 'SYNTAX_OK: ' + path;
    } catch (e) {
        return 'SYNTAX_ERROR: ' + e;
    }
}
run();