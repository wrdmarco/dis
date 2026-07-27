import { redirect } from 'next/navigation';

export default async function Page({ params }: { params: Promise<{ deploymentId: string; userId: string }> }) {
  const { deploymentId, userId } = await params;
  redirect(`/reports/deployments/${encodeURIComponent(deploymentId)}/pilot-reports/${encodeURIComponent(userId)}`);
}
