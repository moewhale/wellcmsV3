<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 将可信磁盘模板展开为一个编译单元；不接收请求参数或运行时视图数据。
 * 状态仅属于一次编译，slot 和源文件缓存不会跨请求保留。
 */
final class TemplateCompiler
{
    private const MAX_DEPTH = 32;
    private const MAX_FILES = 256;
    private const MAX_INCLUDES = 1024;
    private const MAX_BYTES = 8388608;

    private $root;
    private $plugins;
    private $loader;
    private $origin;
    private $sources = [];
    private $nodes = [];
    private $plainSources = [];
    private $identities = [];
    private $owners = [];
    private $active = [];
    private $resolved = [];
    private $includes = 0;
    private $expandedBytes = 0;

    public function __construct(string $root, array $plugins, callable $loader, ?callable $origin = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $this->plugins = $plugins;
        $this->loader = $loader;
        $this->origin = $origin;
    }

    public function compile(string $file, string $source): string
    {
        $this->sources = [$file => $source];
        $this->nodes = [];
        $this->plainSources = [];
        $this->identities = [];
        $this->owners = [];
        $this->active = [];
        $this->resolved = [];
        $this->includes = 0;
        $this->expandedBytes = 0;
        return $this->expandFile($file, [], 0);
    }

    private function expandFile(string $file, array $slots, int $depth): string
    {
        $identity = $this->identities[$file] ?? realpath($file);
        if ($depth > self::MAX_DEPTH || ++$this->includes > self::MAX_INCLUDES) {
            throw new \RuntimeException('Template include limit exceeded');
        }
        if ($identity === false || isset($this->active[$identity])) {
            throw new \RuntimeException('Missing or circular template include: ' . $file);
        }
        $this->identities[$file] = $identity;
        if (!isset($this->sources[$file])) {
            if (count($this->sources) >= self::MAX_FILES) {
                throw new \RuntimeException('Template dependency limit exceeded');
            }
            $this->sources[$file] = ($this->loader)($file);
        }
        $source = $this->sources[$file];
        $this->checkSize(strlen($source));
        $this->active[$identity] = true;
        try {
            if (!isset($this->nodes[$file])) {
                $tokens = $this->tags($source);
                $position = 0;
                $this->nodes[$file] = $this->parse($tokens, $position, null, 0);
            }
            // 无 template/slot 节点的模块展开结果固定，PHP 边界只分析一次。
            if (isset($this->plainSources[$file])) {
                return $this->plainSources[$file];
            }
            $result = $this->render($file, 0, strlen($source), $this->nodes[$file], $slots, $depth);
            // PHP-only 片段（例如 UI 变量）不能让后续父模板 HTML 落入 PHP 代码中。
            if (strpos($result, '<?') !== false) {
                $tokens = token_get_all($result);
                $last = end($tokens);
                if ($last !== false && (!is_array($last) || !in_array($last[0], [T_INLINE_HTML, T_CLOSE_TAG], true))) {
                    $result .= "\n?>";
                }
            }
            if ($this->nodes[$file] === []) {
                $this->plainSources[$file] = $result;
            }
            return $result;
        } finally {
            unset($this->active[$identity]);
        }
    }

    /** 只解析模板 HTML，PHP 字符串、注释和浏览器脚本中的标签保持原样。 */
    private function tags(string $source): array
    {
        if (stripos($source, '<template') === false && stripos($source, '<slot') === false) {
            return [];
        }
        $html = $source;
        if (strpos($source, '<?') !== false) {
            $html = '';
            foreach (token_get_all($source) as $token) {
                $text = is_array($token) ? $token[1] : $token;
                $html .= is_array($token) && $token[0] === T_INLINE_HTML ? $text : str_repeat(' ', strlen($text));
            }
        }
        $html = preg_replace_callback('~<!--.*?-->~s', function (array $match): string {
            return str_repeat(' ', strlen($match[0]));
        }, $html);
        if ($html === null) {
            throw new \RuntimeException('Unable to tokenize template HTML');
        }
        // 顺序跳过其它完整 HTML 标签，属性字符串里的示例标签不作为指令解析。
        $pattern = '~<(/?)([A-Za-z][A-Za-z0-9:-]*)\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~';
        $tags = [];
        $offset = 0;
        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $attributes = $match[3][0];
            $name = strtolower($match[2][0]);
            if ($match[1][0] === '' && in_array($name, ['script', 'style', 'textarea'], true)) {
                $closed = preg_match('~</' . $name . '(?=[\s>])[^>]*>~i', $html, $end, PREG_OFFSET_CAPTURE, $offset);
                $offset = $closed === 1 ? $end[0][1] : strlen($html);
                continue;
            }
            if (!in_array($name, ['template', 'slot'], true)) {
                continue;
            }
            if (count($tags) >= self::MAX_INCLUDES * 4) {
                throw new \RuntimeException('Template directive count limit exceeded');
            }
            $attribute = $name === 'template' ? 'include' : 'name';
            $value = null;
            if (preg_match('~^\s*' . $attribute . '\s*=\s*(["\'])(.*?)\1\s*/?\s*$~s', $attributes, $parts)) {
                $value = $parts[2];
            } elseif ($name === 'template' && preg_match('~\binclude\s*=~i', $attributes)) {
                throw new \RuntimeException('Template include requires one static quoted identifier');
            }
            $tags[] = [
                'name' => $name, 'value' => $value, 'close' => $match[1][0] === '/',
                'single' => substr(rtrim($attributes), -1) === '/',
                'start' => $match[0][1], 'body' => $match[0][1] + strlen($match[0][0]),
            ];
        }
        if (preg_last_error() !== PREG_NO_ERROR) {
            throw new \RuntimeException('Unable to parse template tags');
        }
        return $tags;
    }

    /** 成对解析，避免正则把嵌套 template 或 slot 的第一个结束标签当成外层结束。 */
    private function parse(array $tags, int &$position, ?string $closing, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('Template tag nesting limit exceeded');
        }
        $nodes = [];
        while (isset($tags[$position])) {
            $tag = $tags[$position];
            if ($tag['close']) {
                if ($closing !== $tag['name']) {
                    throw new \RuntimeException('Mismatched template closing tag');
                }
                return $nodes;
            }
            ++$position;
            $tag['children'] = [];
            $tag['endBody'] = $tag['body'];
            $tag['end'] = $tag['body'];
            if (!$tag['single']) {
                $tag['children'] = $this->parse($tags, $position, $tag['name'], $depth + 1);
                if (!isset($tags[$position])) {
                    throw new \RuntimeException('Unclosed template tag');
                }
                $tag['endBody'] = $tags[$position]['start'];
                $tag['end'] = $tags[$position]['body'];
                ++$position;
            }
            $nodes[] = $tag;
        }
        if ($closing !== null) {
            throw new \RuntimeException('Unclosed template tag');
        }
        return $nodes;
    }

    private function render(string $file, int $start, int $end, array $nodes, array $slots, int $depth): string
    {
        $source = $this->sources[$file];
        $output = '';
        foreach ($nodes as $node) {
            $output .= substr($source, $start, $node['start'] - $start);
            $output .= $this->renderNode($file, $node, $slots, $depth);
            $this->checkSize(strlen($output));
            $start = $node['end'];
        }
        $output .= substr($source, $start, $end - $start);
        $this->checkSize(strlen($output));
        return $output;
    }

    private function renderNode(string $file, array $node, array $slots, int $depth): string
    {
        if ($node['name'] === 'template' && $node['value'] !== null) {
            $localSlots = [];
            foreach ($node['children'] as $slot) {
                if ($slot['name'] !== 'slot' || $slot['value'] === null) {
                    throw new \RuntimeException('Template include body only accepts named slots');
                }
                $name = $slot['value'];
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) || array_key_exists($name, $localSlots)) {
                    throw new \RuntimeException('Invalid or duplicate template slot: ' . $name);
                }
                $localSlots[$name] = $this->render($file, $slot['body'], $slot['endBody'], $slot['children'], $slots, $depth);
            }
            $target = $this->resolve($node['value'], $file);
            $result = $this->expandFile($target, $localSlots, $depth + 1);
            $this->expandedBytes += strlen($result);
            $this->checkSize($this->expandedBytes);
            return $result;
        }
        if ($node['name'] === 'slot' && $node['value'] !== null && array_key_exists($node['value'], $slots)) {
            return $slots[$node['value']];
        }
        // 未提供的 slot 与普通 HTML template 保留原标签，兼容原有占位符语义。
        $source = $this->sources[$file];
        return substr($source, $node['start'], $node['body'] - $node['start'])
            . $this->render($file, $node['body'], $node['endBody'], $node['children'], $slots, $depth)
            . substr($source, $node['endBody'], $node['end'] - $node['endBody']);
    }

    private function resolve(string $reference, string $source): string
    {
        $owner = $this->owner($source);
        $key = $owner . ':' . $reference;
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        if (preg_match('~^plugin:([A-Za-z0-9_-]+)/([A-Za-z0-9_./-]+\.(?:htm|html|tpl))$~D', $reference, $match)) {
            $plugin = $match[1];
            if (!isset($this->plugins[$plugin])) {
                throw new \RuntimeException('Template plugin is not installed and enabled: ' . $plugin);
            }
            $this->checkDependency($owner, $plugin);
            $root = $this->plugins[$plugin]['path'] . '/views/htm/';
            // 模板根本身也不能被软链接改指向上传目录或插件私有配置目录。
            if (is_link(rtrim($root, '/')) || is_link(dirname(rtrim($root, '/')))) {
                throw new \RuntimeException('Plugin template root cannot be a symbolic link');
            }
            $relative = $match[2];
            if (preg_match('~(?:^|/)\.\.?(/|$)|//~', $relative)) {
                throw new \RuntimeException('Invalid plugin template identifier');
            }
            $path = $root . $relative;
            $this->checkPath($path, [$root]);
        } else {
            // 保留已有 app/views、themes 的绝对路径和项目相对路径用法。
            if ($reference === '' || preg_match('~[\x00-\x1F]|://|[<>]~', $reference)) {
                throw new \RuntimeException('Invalid template include path');
            }
            $path = $reference[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $reference)
                ? $reference : $this->root . $reference;
            $this->checkPath($path, [$this->root . 'app/views/', $this->root . 'themes/']);
        }
        $this->resolved[$key] = $path;
        return $path;
    }

    private function checkDependency(string $owner, string $target): void
    {
        if ($owner === '' || $owner === $target) {
            return;
        }
        $dependencies = $this->plugins[$owner]['config']['dependencies'] ?? [];
        if (!isset($dependencies[$target])
            || !version_compare((string)($this->plugins[$target]['config']['version'] ?? '0'), (string)$dependencies[$target], '>=')) {
            throw new \RuntimeException('Undeclared or unsatisfied template plugin dependency: ' . $target);
        }
    }

    private function owner(string $file): string
    {
        if (isset($this->owners[$file])) {
            return $this->owners[$file];
        }
        $source = $file;
        // 覆盖模板的插件对其引用负责，不把覆盖文件错误地当成原模板插件的源码。
        $file = $this->origin === null ? $file : ($this->origin)($file);
        $file = str_replace('\\', '/', $file);
        foreach ($this->plugins as $name => $plugin) {
            $prefix = rtrim(str_replace('\\', '/', $plugin['path']), '/') . '/';
            if (strpos($file, $prefix) === 0) {
                return $this->owners[$source] = (string)$name;
            }
        }
        return $this->owners[$source] = '';
    }

    private function checkPath(string $path, array $roots): void
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('Template file not found or unreadable: ' . $path);
        }
        $real = str_replace('\\', '/', $real);
        foreach ($roots as $root) {
            $directory = realpath($root);
            if ($directory === false) {
                continue;
            }
            $prefix = rtrim(str_replace('\\', '/', $directory), '/') . '/';
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? strncasecmp($real, $prefix, strlen($prefix)) === 0
                : strncmp($real, $prefix, strlen($prefix)) === 0;
            if ($inside) {
                return;
            }
        }
        throw new \RuntimeException('Template include is outside allowed template directories: ' . $path);
    }

    private function checkSize(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new \RuntimeException('Template expansion size limit exceeded');
        }
    }
}
