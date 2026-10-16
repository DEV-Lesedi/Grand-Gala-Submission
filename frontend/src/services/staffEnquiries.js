// Staff enquiries, already in the page's shape. Each status list is loaded in full.
import { allPages } from './api'

export async function loadAllStaffEnquiries() {
  const statuses = ['enquiry', 'quoted', 'closed']
  const lists = await Promise.all(statuses.map((status) => allPages(`/staff/enquiries?status=${status}`, 'enquiries')))
  return lists.flat()
}
