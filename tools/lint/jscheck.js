function run() {
    var args = $.NSProcessInfo.processInfo.arguments;
    var path = '';
    // 从后向前取第一个 .js 目标文件：跳过 osascript 自身选项（-l 等）与脚本自身
    for (var i = args.count - 1; i >= 0; i--) {
        var a = String(args.objectAtIndex(i).js);
        if (a.charAt(0) === '-') continue;
        if (/jscheck\.js$/.test(a)) continue;
        if (/\.js$/i.test(a)) { path = a; break; }
    }
    if (path === '') return 'NO_ARGS（用法：osascript -l JavaScript tools/lint/jscheck.js <file.js>）';
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
