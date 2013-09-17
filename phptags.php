#!/usr/bin/env php
<?php
namespace ptags;

$fileCache = array();

class FileCache {
  public $realpath;
  public $valid = false;
  public $tags = array();

  public static function load($realpath) {
    $cachePath = self::getCachePath($realpath);
    $mtime = filemtime($realpath);

    if (!is_readable($cachePath) || filemtime($cachePath) < $mtime) {
      $fc = new self;
      $fc->realpath = $realpath;
      return $fc;
    }

    return unserialize(file_get_contents($cachePath));
  }

  public function save() {
    $this->valid = true;
    $cachePath = self::getCachePath($this->realpath);

    if (!is_dir(dirname($cachePath))) {
      mkdir(dirname($cachePath), 0700, true);
    }

    file_put_contents(self::getCachePath($this->realpath), serialize($this));
  }

  public static function getCachePath($realpath) {
    return getenv('HOME')."/.ptags/{$realpath}";
  }
}

class Parser {
  private $tags = array();
  private $scopes = array();
  private $filename = NULL;
  private $tokens;
  private $fc;

  private function skipToChar(array $chars) {
    while ($tok = $this->next())
      if (in_array($tok, $chars))
        return $tok;

    return NULL;
  }

  public function parseFile($filename) {
    $realpath = realpath($filename);
    $this->fc = FileCache::load($realpath);
    if ($this->fc->valid) {
      foreach ($this->fc->tags as $tag) {
        $this->tags[] = $tag;
      }

      return;
    }

    $this->resetScope();
    $this->filename = $realpath;

    $this->tokens = token_get_all(file_get_contents($filename));

    for ($tok = current($this->tokens); $tok !== false; $tok = $this->next()) {
      switch ($tok[0]) {
      case T_NAMESPACE:
        $this->resetScope();
        $this->parseNamespace();
        break;

      case T_INTERFACE:
        $this->parseInterface();
        break;

      case T_CLASS:
        $this->parseClass();
        break;

      case T_FUNCTION:
        $this->parseFunction();
        break;

      case T_CONST:
        $this->parseConstant();
        break;
      }
    }

    $this->fc->save();
  }

  public function dump($basePath) {
    $result = array();
    foreach ($this->tags as $tag) {
      $tag[1] = $this->toRelPath($basePath, $tag[1]);
      $result[] = implode("\t", $tag);
    }
    sort($result);
    return implode("\n", $result)."\n";
  }

  private function toRelPath($base, $dest) {
    $baseParts = explode('/', rtrim($base, '/'));
    $destParts = explode('/', rtrim($dest, '/'));

    while (count($baseParts) && count($destParts) && $baseParts[0] === $destParts[0]) {
      array_shift($baseParts);
      array_shift($destParts);
    }

    $prefix = count($baseParts) ? implode('/', array_fill(0, count($baseParts), '..')).'/' : '';
    return $prefix.implode('/', $destParts);
  }

  private function createTag($name, $type, $access, $lineno, $pattern, array $extra=array()) {
    $pattern = $this->getScopedPattern($pattern);
    $pattern = str_replace('\\', '\\\\', $pattern);
    $pattern = str_replace('$', '\\$', $pattern);
    $pattern = str_replace('^', '\\^', $pattern);
    $pattern = str_replace("\n", '$', $pattern);
    $tag = array_merge(array(
      $name,
      $this->filename,
      "let _s=@/ | /{$pattern}/; | let @/=_s\";\"",
      $type,
      "lineno:{$lineno}",
      $this->getScope(),
    ), $extra);

    if ($access !== NULL) {
      $tag[] = "access:{$access}";
    }

    $this->tags[] = $tag;
    $this->fc->tags[] = $tag;
  }

  private function parseConstant() {
    $this->parseNamed('d');
  }

  private function parseNamespace() {
    $this->startCollect();

    while ($tok = $this->next()) {
      switch ($tok[0]) {
      case T_STRING:
        $lineno = $tok[2];
        $name = $tok[1];
        break;

      case T_NS_SEPARATOR:
        if (isset($name))
          $this->pushScope('namespace', $name);

        break;

      case '{':
        $pattern = $this->stopCollect();
        if (isset($name)) {
          $this->createTag($name, 'n', NULL, $lineno, $pattern);
          $this->pushScope('namespace', $name, $pattern);
        }

        $this->parseScopedNamespace();

        if (isset($name)) {
          $this->popScope();
        }
        break 2;

      case ';':
        $pattern = $this->stopCollect();
        if (isset($name)) {
          $this->createTag($name, 'n', NULL, $lineno, $pattern);
          $this->pushScope('namespace', $name, $pattern);
        }
        break 2;
      }
    }
  }

  private function parseScopedNamespace() {
    while ($tok = $this->next()) {
      switch ($tok[0]) {
      case T_INTERFACE:
        $this->parseInterface();
        break;

      case T_CLASS:
        $this->parseClass();
        break;

      case T_FUNCTION:
        $this->parseFunction();
        break;

      case T_CONST:
        $this->parseConstant();
        break;

      case '}':
        break 2;
      }
    }
  }

  private function skipBlock() {
    while ($tok = $this->next()) {
      switch ($tok[0]) {
      case '{':
      case T_CURLY_OPEN:
        $this->skipBlock();
        break;

      case '}':
        break 2;
      }
    }
  }

  private function parseInterface() {
    list($name, $pattern) = $this->parseNamed('i');
    $this->pushScope('interface', $name, $pattern);
    $this->skipToChar(array('{'));

    while ($tok = $this->next()) {
      switch ($tok[0]) {
      case T_PRIVATE:
      case T_PROTECTED:
      case T_PUBLIC:
      case T_VAR:
        $this->parseVisible();
        break;

      case T_CONST:
        $this->parseConstant();
        break;

      case T_FUNCTION:
        $this->parseFunction('public');
        break;

      case '}':
        break 2;
      }
    }
    $this->popScope();
  }

  private function parseClass() {
    list($name, $pattern) = $this->parseNamed('c');
    $this->pushScope('class', $name, $pattern);
    $this->skipToChar(array('{'));

    while ($tok = $this->next()) {
      switch ($tok[0]) {
      case T_PRIVATE:
      case T_PROTECTED:
      case T_PUBLIC:
      case T_VAR:
        $this->parseVisible();
        break;

      case T_CONST:
        $this->parseConstant();
        break;

      case T_FUNCTION:
        $this->parseFunction('public');
        break;

      case '{':
      case T_CURLY_OPEN:
        $this->skipBlock();
        break;

      case '}':
        break 2;
      }
    }
    $this->popScope();
  }

  private function parseVisible() {
    $this->startCollect();

    for ($tok = current($this->tokens); $tok !== false; $tok = $this->next()) {
      switch ($tok[0]) {
      case T_PRIVATE:
        $access = 'private';
        break;

      case T_PROTECTED:
        $access = 'protected';
        break;

      case T_PUBLIC:
      case T_VAR:
        $access = 'public';
        break;

      case T_VARIABLE:
        $this->createTag($tok[1], 'v', $access, $tok[2], $this->stopCollect());
        break;

      case T_FUNCTION:
        $this->parseFunction($access);
        break 2;

      case ';':
        break 2;
      }
    }
  }

  private $collect = false;

  private function startCollect() {
    $tok = current($this->tokens);
    $this->snippet = $tok[1];
    $this->collect = true;
  }

  private function stopCollect() {
    $this->collect = false;
    return $this->snippet;
  }

  private function next() {
    $tok = next($this->tokens);
    if ($this->collect) {
      $part = is_array($tok) ? $tok[1] : $tok;
      $this->snippet .= $part;
      if (strpos("\n", $part) !== false)
        $this->collect = false;
    }
    return $tok;
  }

  private function parseFunction($access=NULL) {
    $this->startCollect();
    $tok = current($this->tokens);
    $lineno = $tok[2];
    $extra = array();

    $stopCollect = false;
    while ($tok = $this->next()) {
      if ($stopCollect)
        $pattern = $this->stopCollect();

      switch ($tok[0]) {
      case T_STRING:
        $name = $tok[1];
        $stopCollect = true;
        break;

      case '(':
        $extra[] = "signature:{$this->parseParameterList()}";
        break;

      case '{':
        $this->skipBlock();
        break 2;

      case ';':
        break 2;
      }
    }

    if (isset($name, $pattern))
      $this->createTag($name, 'f', $access, $lineno, $pattern, $extra);
  }

  private function parseParameterList() {
    $parts = array();
    $depth = 0;
    for ($tok = current($this->tokens); $tok !== false; $tok = $this->next()) {
      $parts[] = is_array($tok) ? $tok[1] : $tok;

      switch ($tok) {
      default:
        break;

      case '(':
        $depth++;
        break;

      case ')':
        if (--$depth)
          break;
        else
          break 2;
      }
    }

    return preg_replace('/\s+/', ' ' , implode('', $parts));
  }

  private function parseNamed($type, $access=NULL) {
    $tok = current($this->tokens);
    $this->startCollect();
    $lineno = $tok[2];

    while ($tok = $this->next()) {
      if ($tok[0] == T_STRING) {
        $name = $tok[1];
        break;
      }
    }

    $pattern = $this->stopCollect();

    $this->createTag($name, $type, $access, $lineno, $pattern);

    return array($name, $pattern);
  }

  private function resetScope() {
    $this->scopes = array();
  }

  private function pushScope($type, $name, $pattern=NULL) {
    $this->scopes[] = array($type, $name, $pattern);
  }

  private function popScope() {
    array_pop($this->scopes);
  }

  private function getScope() {
    $curScope = end($this->scopes);
    return "{$curScope[0]}:".implode('::', array_map(function($x) { return $x[1]; }, $this->scopes));
  }

  private function getScopedPattern($pattern) {
    $parts = array();
    foreach ($this->scopes as $scope) {
      if (isset($scope[2]))
        $parts[] = $scope[2];
    }
    $parts[] = $pattern;
    return implode('/;/', $parts);
  }
}

function recurseDir($path, $ignore=array()) {
  $files = array();

  $dh = opendir($path);
  while ($fn = readdir($dh)) {
    if (in_array($fn, array('.', '..')))
      continue;

    if ($path !== '.')
      $fullname = "{$path}/{$fn}";
    else
      $fullname = $fn;


    foreach ($ignore as $rule) {
      if (preg_match($rule, $fullname)) {
        continue 2;
      }
    }

    if (is_dir($fullname)) {
      $files = array_merge($files, recurseDir($fullname, $ignore));
    } elseif (preg_match('/^.*\.php$/i', $fullname)) {
      $files[] = $fullname;
    }
  }
  closedir($dh);

  return $files;
}

$outfile = 'tags';
$recurse = false;
$files = array();

next($argv);
while ($e = each($argv)) {
  list ($k, $v) = $e;
  switch ($v) {
  case '-f':
    $outfile = current($argv);
    next($argv);
    break;

  case '-R':
    $recurse = true;
    break;

  default:
    if (is_dir($v)) {
      if ($recurse) {
        $files = array_merge($files, recurseDir($v, array('!(^|/).git$!', '!^app/cache$!')));
      } else {
        die("{$v} is a directory, maybe try -R?\n");
      }
    } else {
      $files[] = $v;
    }
  }
}

$parser = new Parser();
foreach ($files as $file)
  $parser->parseFile($file);

if ($outfile == '-') {
  echo $parser->dump('.');
} else {
  file_put_contents($outfile, $parser->dump(realpath(dirname($outfile))));
}
