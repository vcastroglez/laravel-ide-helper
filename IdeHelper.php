<?php

namespace App\Console\Commands;

use Barryvdh\Reflection\DocBlock;
use Illuminate\Console\Command;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class IdeHelper extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'app:ide-help {--class= : Class name to apply the docblock}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Add Builder mixin and PHPDocBlocks to Models and Classes, for the IDE to detect his methods';

	/**
	 * @return void
	 * @throws ReflectionException
	 */
	public function handle(): void
	{
		$class = $this->option('class') ?? null;
		$models_folder = app_path('Models');

		$this->generateModelsDocBlock($models_folder, $class);
		$this->generateClassAndMethodsBlock($models_folder, $class, true);
		$all_http_directories = File::directories(app_path('Http'));
		foreach ($all_http_directories as $directory) {
			$this->generateClassAndMethodsBlock($directory, $class);
		}
	}

	/**
	 * @param SplFileInfo $file
	 *
	 * @return string
	 */
	public function getNamespace(SplFileInfo $file): string
	{
		$match = [];
		preg_match('/\nnamespace\s+(.*);/', $file->getContents(), $match);
		return $match[1];
	}

	private function getClass(SplFileInfo $file)
	{
		$match = [];
		preg_match('/\nclass\s+(\w+)\s/', $file->getContents(), $match);
		return $match[1];
	}

	/**
	 * @param string $mysql_type
	 *
	 * @return string
	 */
	private function getPhpType(string $mysql_type): string
	{
		if (str_contains($mysql_type, 'int')) {
			return "int";
		} else if (str_contains($mysql_type, 'varchar')) {
			return "string";
		} else if (str_contains($mysql_type, 'timestamp')) {
			return "string";
		}

		return "string";
	}

	/**
	 * @throws ReflectionException
	 */
	private function generateClassAndMethodsBlock(string $target_folder, string $class = null, bool $skip_class_doc = false): void
	{
		$order_with_space = [
			'comment'    => true,
			'@property'  => true,
			'@class'     => false,
			'@namespace' => false
		];

		$files = $this->getFilesInFolder($target_folder, $class);
		foreach ($files as $file) {
			$namespace = $this->getNamespace($file);
			$class = $this->getClass($file);
			$full_class = "$namespace\\$class";
			$reflection = new ReflectionClass($full_class);
			$content = $original_content = $file->getContents();
			if (!$skip_class_doc) {
				$original_doc_block = $reflection->getDocComment();
				$existing_tags = $this->groupCommentTags($original_doc_block);

				if (empty($existing_tags['@class'])) {
					$existing_tags['@class'][] = " * @class $class";
				}
				if (empty($existing_tags['@namespace'])) {
					$existing_tags['@namespace'][] = " * @namespace $namespace";
				}

				$existing_tags['@property'] = $this->getMissingPublicProperties($reflection, $existing_tags['@property']
					?? []);

				$final_doc_block = $this->getOrderedDocBlock($existing_tags, $order_with_space) . " $class";

				if (!$original_doc_block) {
					$original_doc_block = "class $class";
				} else {
					$original_doc_block .= PHP_EOL . "class $class";
				}

				$count = 0;
				$content = str_replace($original_doc_block, $final_doc_block, $original_content, $count);
				if (!$count) {
					continue;
				}
			}

			$original_lines = preg_split("/((\r?\n)|(\r\n?))/", $original_content);
			$use_statements = $this->getUseStatements($original_lines);
			$use_statements[] = $namespace . '\\';
			foreach ($this->getMethodsDocBlocks($reflection) as $doc_block_data) {
				list($start, $doc) = $doc_block_data;
				$method_line = $original_lines[$start];
				$doc = $this->prependTabs(substr_count($method_line, "\t"), $doc) . PHP_EOL . $method_line;
				$doc = str_replace($use_statements, '', $doc);
				$content = str_replace($method_line, $doc, $content);
			}


			File::put($file->getRealPath(), $content);
		}
	}

	/**
	 * @param ReflectionClass $reflection
	 *
	 * @return array
	 */
	public function getMethodsDocBlocks(ReflectionClass $reflection): array
	{
		$to_return = [];
		$current_class = $reflection->getName();
		$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
		foreach ($methods as $method) {
			if ($method->getDocComment()) {
				continue;
			}

			if ($method->getDeclaringClass()->getName() != $current_class) {
				continue;
			}

			$method_line = $method->getStartLine() - 1;
			$method_nice_doc = $this->getMethodDoc($method);
			$to_return[] = [$method_line, $method_nice_doc];
		}

		return $to_return;
	}

	/**
	 * @param ReflectionMethod $method
	 *
	 * @return string
	 */
	public function getMethodDoc(ReflectionMethod $method): string
	{
		$params = $method->getParameters();
		$to_implode = [];
		$longest_line = 0;
		foreach ($params as $param) {
			$line = ' * @param ' . $param->getType()->getName() . ' $' . $param->getName();
			$line_length = strlen(preg_replace('/\s(\$[a-zA-Z0-9_]+)/', '', $line));
			if ($line_length > $longest_line) {
				$longest_line = $line_length;
			}
			$to_implode[] = $line;
		}

		$to_implode = $this->alignLines($to_implode, $longest_line);

		$return_type = $method->getReturnType();
		if (!is_null($return_type)) {
			if (!empty($to_implode)) {
				$to_implode[] = ' * ';
			}
			$to_implode[] = ' * @return ' . $return_type;
		}

		return "/**\n" . implode("\n", $to_implode) . "\n */";
	}

	/**
	 * @param array $tags
	 * @param array $order
	 *
	 * @return string
	 */
	private function getOrderedDocBlock(array $tags, array $order): string
	{
		$final_form = "";
		foreach ($order as $tag => $space) {
			if (empty($tags[$tag])) {
				continue;
			}

			$combined = implode("\n", $tags[$tag]) ?: "";
			$final_form .= $combined;
			if ($space) {
				$final_form .= "\n * \n";
			} else {
				$final_form .= "\n";
			}
		}

		return "/**\n$final_form */\nclass";
	}

	private function getMissingPublicProperties(ReflectionClass $reflection, array $already_tagged = []): array
	{
		$properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

		$to_return = [];
		foreach ($properties as $property) {
			$tag = " * @property";
			$type = $property->getType()->getName();
			$name = $property->getName();
			$type_name = "$type \$$name";

			$existing = $this->arrayStringMatch($already_tagged, "$type \$$name");
			if ($existing != -1) {
				$to_return[] = $already_tagged[$existing];
				continue;
			}
			$to_return[] = "$tag $type_name";
		}

		return $to_return;
	}

	private function arrayStringMatch($arr, $keyword): int
	{
		foreach ($arr as $index => $string) {
			if (str_contains($string, $keyword))
				return $index;
		}

		return -1;
	}

	private function getFilesInFolder(string $target_folder, string $class = null): array
	{
		$files = File::allFiles($target_folder);
		if (!empty($class)) {
			$files = array_filter($files, function($file) use ($class){
				return str_replace('.php', '', $file->getFilename()) == $class;
			});
		}

		if (empty($files)) {
			return [];
		}

		return $files;
	}

	/**
	 * @param string $target_folder
	 * @param string|null $class
	 *
	 * @return void
	 * @throws ReflectionException
	 */
	private function generateModelsDocBlock(string $target_folder, string $class = null): void
	{
		$files = $this->getFilesInFolder($target_folder, $class);

		$connections = [];
		foreach ($files as $file) {
			$namespace = $this->getNamespace($file);
			$class = $this->getClass($file);
			$full_class = "$namespace\\$class";
			$reflection = new ReflectionClass($full_class);
			$originalDoc = $reflection->getDocComment();
			$phpdoc = new DocBlock($reflection, new DocBlock\Context($namespace));

			$mixins = $phpdoc->getTagsByName('mixin');
			$expectedMixins = [
				'\Illuminate\Database\Eloquent\Builder' => false,
			];

			foreach ($mixins as $m) {
				$mixin = $m->getContent();

				if (isset($expectedMixins[$mixin])) {
					$expectedMixins[$mixin] = true;
				}
			}

			//Properties tags
			try {
				$instance = new $full_class();
				/** @var MySqlConnection $database */
				$connection_name = $instance->getConnection()->getConfig('name');
				$table = $instance->getTable();
				$connection = $connections[$connection_name] ??= Schema::connection($connection_name);

				$properties = $connection->getColumnListing($table);

				$current_properties = array_map(fn (DocBlock\Tag\PropertyTag $property) => $property->getVariableName(),
					$phpdoc->getTagsByName('property'));

				$properties_count = count($properties) - 1;
				foreach ($properties as $i => $property) {
					if (in_array("\$$property", $current_properties)) {
						continue;
					}
					$mysql_type = $connection->getColumnType($table, $property);
					$php_type = $this->getPhpType($mysql_type);
					$append = $i == $properties_count ? PHP_EOL : '';
					$phpdoc->appendTag(DocBlock\Tag\PropertyTag::createInstance("@property $php_type \$$property$append", $phpdoc));
				}
			} catch (Throwable) {
				//don't do anything if instance can't be created
			}

			//Class tag
			if (!$phpdoc->getTagsByName('class')) {
				$phpdoc->appendTag(DocBlock\Tag::createInstance('@class {' . $class . '}', $phpdoc));
			}
			//Namespace tag
			if (!$phpdoc->getTagsByName('namespace')) {
				$phpdoc->appendTag(DocBlock\Tag::createInstance('@namespace {' . $namespace . '}' . PHP_EOL, $phpdoc));
			}

			//Builder mixin
			foreach ($expectedMixins as $expectedMixin => $present) {
				if ($present === false) {
					$phpdoc->appendTag(DocBlock\Tag::createInstance('@mixin ' . $expectedMixin, $phpdoc));
				}
			}


			$serializer = new DocBlock\Serializer();
			$serializer->getDocComment($phpdoc);
			$docComment = $serializer->getDocComment($phpdoc);

			if (!$originalDoc) {
				$originalDoc = 'class';
			} else {
				$originalDoc .= PHP_EOL . 'class';
			}
			$docComment .= PHP_EOL . 'class';
			$docComment = $this->reorderModelDocComment($docComment);

			$count = 0;
			$content = str_replace($originalDoc, $docComment, $file->getContents(), $count);
			if (!$count || !$file->isWritable()) { //Nothing changed
				continue;
			}
			File::put($file->getRealPath(), $content);
		}
	}

	/**
	 * @param string $doc_comment
	 *
	 * @return string
	 */
	private function cleanDuplications(string $doc_comment): string
	{
		//Remove spaces after end of line
		$doc_comment = str_replace(" \n", "\n", $doc_comment);
		//Remove double spaces
		$doc_comment = str_replace("  ", " ", $doc_comment);
		//remove double * lines
		return preg_replace("/( \*\n)+/", " *\n", $doc_comment);
	}

	/**
	 * @param string $doc_comment
	 *
	 * @return array
	 */
	private function groupCommentTags(string $doc_comment): array
	{
		$grouped = [];
		$lines = explode("\n", $doc_comment);
		foreach ($lines as $line) {
			$matches = [];
			$is_tag = preg_match("/\* (@[a-z]+) /", $line, $matches);
			if (!$is_tag) {
				$is_empty_line = preg_match('/\/\*\*/', $line) ||
					preg_match('/ \*$/', $line) ||
					preg_match('/ \*\//', $line) ||
					!str_contains($line, '*');
				if ($is_empty_line) continue;
				$grouped['comment'][] = $line;
			} else {
				$grouped[$matches[1]][] = $line;
			}
		}
		return $grouped;
	}

	/**
	 * @param string $doc_comment
	 *
	 * @return string
	 */
	private function reorderModelDocComment(string $doc_comment): string
	{
		//Remove spaces after end of line
		$doc_comment = $this->cleanDuplications($doc_comment);

		//group them
		$grouped = $this->groupCommentTags($doc_comment);

		//The order is comment -> properties -> class definition and mixin
		$comment = implode("\n", ($grouped['comment'] ?? [])) ?: " * <Class description here>";
		$properties = $this->alignLines(array_unique($grouped['@property'] ?? []));
		$properties = implode("\n", $properties) ?: " *";

		$class_definition = [$grouped['@class'][0], $grouped['@namespace'][0]];
		$class_definition = $this->alignLines($class_definition);

		$class_definition = implode("\n", $class_definition);
		$mixins = implode("\n", array_unique($grouped['@mixin'])) ?? "";
		$internal_parts = "/**\n" . implode("\n *\n", [
				$comment,
				$properties,
				$class_definition,
				$mixins
			]) . "\n */";

		//remove double * lines again
		$internal_parts = preg_replace("/( \*\n)+/", " *\n", $internal_parts);

		return $internal_parts . "\nclass";
	}

	/**
	 * @param int $amount
	 * @param string $doc_block
	 *
	 * @return null|array|string|string[]
	 */
	private function prependTabs(int $amount, string $doc_block): array|string|null
	{
		$prefix = str_repeat("\t", $amount);

		return preg_replace('/^/m', $prefix, $doc_block);
	}

	/**
	 * @param array $original_lines
	 *
	 * @return array
	 */
	private function getUseStatements(array $original_lines): array
	{
		$uses = [];

		$starting = false;
		$end_searching = 3;
		foreach ($original_lines as $line) {
			if (str_starts_with($line, 'use')) {
				$clean = trim(str_replace(['use', ';'], '', $line));
				$uses[] = preg_replace('/\\\[a-zA-Z]+$/', '\\', $clean);
				$starting = true;
				continue;
			}

			if ($starting && $end_searching-- <= 0) {
				break;
			}
		}

		return $uses;
	}

	/**
	 * @param array $lines
	 * @param ?int $longest_line
	 *
	 * @return array
	 */
	private function alignLines(array $lines, ?int $longest_line = null): array
	{
		$var_regexp = '/\s([{|$][a-zA-Z0-9_\\\]+}?)/';
		if (is_null($longest_line)) {
			foreach ($lines as $line) {
				$line_length = strlen(preg_replace($var_regexp, '', $line));
				if ($line_length > $longest_line) {
					$longest_line = $line_length;
				}
			}
		}

		$to_return = [];
		foreach ($lines as $line) {
			$length = strlen(preg_replace($var_regexp, '', $line));
			if ($length == $longest_line) {
				$to_return[] = $line;
				continue;
			}

			$to_pad = $longest_line - $length + 1;
			$spaces = str_repeat(" ", $to_pad);
			$to_return[] = preg_replace($var_regexp, "$spaces$1", $line);
		}

		return $to_return;
	}
}
