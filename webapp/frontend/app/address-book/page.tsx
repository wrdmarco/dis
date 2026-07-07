'use client';

import { AddressBookPage } from '../../src/features/address-book/AddressBookPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['address-book.view']}>
      <AddressBookPage />
    </ProtectedShell>
  );
}
