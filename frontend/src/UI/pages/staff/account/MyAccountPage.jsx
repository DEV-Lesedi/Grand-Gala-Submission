import { Link, useNavigate } from 'react-router-dom'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import { useStaffUser } from '../../../hooks/staffUser'
import StaffPageHeader from '../components/StaffPageHeader'
import DetailsForm from './DetailsForm'
import PasswordForm from './PasswordForm'
import { initials } from './accountRules'
import { canSeeTeam } from '../team/teamRules'
import { formatDate } from '../../../../lib/dates'
import { STAFF_ROLES } from '../../../../lib/statuses'
import './MyAccountPage.css'

export default function MyAccountPage() {
  const { user, signOut, saveDetails, changePassword } = useStaffUser()
  const navigate = useNavigate()

  async function handleSignOut() {
    await signOut()
    navigate('/staff/sign-in')
  }

  return (
    <div className="account-page">
      <StaffPageHeader title="My account" subtitle="Your profile, details and password." mobileSubtitle={null} />

      <div className="account-body">
        <Card as="section" className="account-profile" aria-label="Profile">
          <span className="account-avatar">{initials(user)}</span>
          <h2 className="account-name">
            {user.name} {user.surname}
          </h2>
          <StatusChip statuses={STAFF_ROLES} status={user.role} />
          <dl className="account-facts">
            <dt>Email</dt>
            <dd>{user.email}</dd>
            <dt>Phone</dt>
            <dd>{user.phone || '—'}</dd>
          </dl>
          {/* "10 Feb 2026" → "Feb 2026" */}
          {user.dateCreated && <p className="account-since">Member since {formatDate(user.dateCreated).slice(3)}</p>}
          {/* Phones have no sidebar, so Team is reached from here */}
          {canSeeTeam(user) && (
            <Link to="/staff/team" className="account-team-link">
              Manage the team →
            </Link>
          )}
          <Button variant="secondary" className="account-sign-out" onClick={handleSignOut}>
            Sign out
          </Button>
        </Card>

        <div className="account-forms">
          <DetailsForm user={user} onSave={saveDetails} />
          <PasswordForm onChange={changePassword} />
        </div>
      </div>
    </div>
  )
}
