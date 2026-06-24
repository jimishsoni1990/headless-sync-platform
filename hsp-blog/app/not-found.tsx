import Link from "next/link";

export default function NotFound() {
  return (
    <div className="text-center py-20">
      <h1 className="text-4xl font-bold mb-4">404</h1>
      <p className="text-gray-500 mb-6">This page could not be found.</p>
      <Link href="/posts" className="text-blue-600 hover:underline">
        Back to blog
      </Link>
    </div>
  );
}
