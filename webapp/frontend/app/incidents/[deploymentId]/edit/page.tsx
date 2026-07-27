import { redirect } from 'next/navigation';

export default async function Page({ params }: { params: Promise<{ deploymentId: string }> }) {
  const { deploymentId } = await params;
  redirect(`/inzetten/${encodeURIComponent(deploymentId)}/edit`);
}
