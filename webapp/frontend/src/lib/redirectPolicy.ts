export function canonicalRedirectPath(pathname: string): string {
  const path = pathname.replace(/^\/+/, '').replace(/\/+$/, '');

  return path === '' ? '/' : `/${path}`;
}

export function validatedCanonicalRedirectOrigin(value?: string): string | null {
  if (value === undefined || value.trim() === '' || /[\r\n]/.test(value)) {
    return null;
  }

  try {
    const url = new URL(value.trim());
    if (
      url.protocol !== 'https:'
      || url.username !== ''
      || url.password !== ''
      || url.pathname !== '/'
      || url.search !== ''
      || url.hash !== ''
    ) {
      return null;
    }

    return url.origin;
  } catch {
    return null;
  }
}
