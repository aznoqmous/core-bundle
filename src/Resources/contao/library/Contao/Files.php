	final public function __clone()
	{
	}
		if (file_exists(TL_ROOT . '/' . $strDirectory))
		return mkdir(TL_ROOT . '/' . $strDirectory);
		if (!file_exists(TL_ROOT . '/' . $strDirectory))
		return rmdir(TL_ROOT . '/' . $strDirectory);
		$arrFiles = scan(TL_ROOT . '/' . $strFolder, true);
			if (is_link(TL_ROOT . '/' . $strFolder . '/' . $strFile))
			elseif (is_dir(TL_ROOT . '/' . $strFolder . '/' . $strFile))
		return fopen(TL_ROOT . '/' . $strFile, $strMode);
		if (\defined('PHP_WINDOWS_VERSION_BUILD') && file_exists(TL_ROOT . '/' . $strNewName) && strcasecmp($strOldName, $strNewName) !== 0)
			rename(TL_ROOT . '/' . $strOldName, TL_ROOT . '/' . $strOldName . '__');
		return rename(TL_ROOT . '/' . $strOldName, TL_ROOT . '/' . $strNewName);
		return copy(TL_ROOT . '/' . $strSource, TL_ROOT . '/' . $strDestination);
		$arrFiles = scan(TL_ROOT . '/' . $strSource, true);
			if (is_dir(TL_ROOT . '/' . $strSource . '/' . $strFile))
		return unlink(TL_ROOT . '/' . $strFile);
		return chmod(TL_ROOT . '/' . $strFile, $varMode);
		return is_writable(TL_ROOT . '/' . $strFile);
		return move_uploaded_file($strSource, TL_ROOT . '/' . $strDestination);
			if ($strPath == '')
			if (\Validator::isInsecurePath($strPath))