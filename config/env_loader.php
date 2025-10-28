<?php
// Simple .env loader without external dependencies.
// Loads KEY=VALUE pairs into getenv/$_ENV/$_SERVER if not already set.

function cjph_load_dotenv($path)
{
	if (!is_readable($path)) {
		return false;
	}
	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return false;
	}
	foreach ($lines as $line) {
		$trimmed = trim($line);
		if ($trimmed === '' || $trimmed[0] === '#') {
			continue;
		}
		// Support lines like KEY="VALUE WITH SPACES" and strip surrounding quotes
		$parts = explode('=', $line, 2);
		if (count($parts) !== 2) {
			continue;
		}
		$key = trim($parts[0]);
		$value = trim($parts[1]);
		if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
			$value = substr($value, 1, -1);
		}
		if (!isset($_ENV[$key]) && !getenv($key)) {
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
			@putenv($key . '=' . $value);
		}
	}
	return true;
}

// Auto-load from project root if a .env exists
$projectRoot = dirname(__DIR__);
$defaultEnv = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (is_readable($defaultEnv)) {
	cjph_load_dotenv($defaultEnv);
}

?>


