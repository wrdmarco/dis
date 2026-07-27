import { redirect } from 'next/navigation';

export default async function Page({ params }: { params: Promise<{ deploymentRequestId: string }> }) {
  const { deploymentRequestId } = await params;
  redirect(`/aanvragen/${encodeURIComponent(deploymentRequestId)}`);
}
