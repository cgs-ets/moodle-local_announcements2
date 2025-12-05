import { useEffect, useState } from "react";
import { Box, Container, Center, Text, TextInput, Breadcrumbs, Modal, Button, Group, Grid, Checkbox } from '@mantine/core';
import { useNavigate } from "react-router-dom";
import { Link as RouterLink } from 'react-router-dom';
import { Header } from "../../components/Header";
import { Footer } from "../../components/Footer";
import { getConfig } from "../../utils";
import useFetch from "../../hooks/useFetch";
import { FileUploader } from "../../components/FileUploader";
import { Announcement, Errors, Row, User, Audience } from "../../types/types";
import { PageBuilder } from "../../components/PageBuilder";
import { IconEye, IconSend } from "@tabler/icons-react";
import { PagePreview } from "../../components/PagePreview";
import { UserCombobox } from "../../components/UserCombobox";
import { DateTimePicker } from "@mui/x-date-pickers";
import dayjs from "dayjs";
import { AudienceSelector } from "../../components/AudienceSelector";


// Generate unique ID
const generateId = () => `id-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

export function Create() {
  const api = useFetch();
  const [errors, setErrors] = useState<Errors>({} as Errors)
  const [data, setData] = useState<Announcement>({
    id: 0,
    username: "",
    subject: "",
    message: "",
    timecreated: "",
    timemodified: "",
    timestart: dayjs().unix().toString(),
    endenabled: false,
    timeend: dayjs().unix().toString(),
    deleted: false,
    forcesend: false,
    attachments: "",
    existingattachments: [],
    uploadedimages: "",
    impersonate: [] as User[],
    audiences: [] as Audience[],
  })
  const [sendAsOptions, setSendAsOptions] = useState<User[]>([])
  const [audiences, setAudiences] = useState<Audience[]>([])
  // Initialize page builder with a single row and single block
  const [pageBuilderRows, setPageBuilderRows] = useState<Row[]>([
    {
      id: generateId(),
      blocks: [{
        id: generateId(),
        type: 'text',
        content: '',
      }],
    }
  ])
  const [preview, setPreview] = useState(false)
  const navigate = useNavigate()
  document.title = 'Post Announcement'

  useEffect(() => {
    loadData()
  }, []);

  const loadData = async () => {
    const [sendAsOptionsResponse, audiencesResponse] = await Promise.all([
      getSendAsOptions(),
      getAudiences()
    ]);
    if (sendAsOptionsResponse.error) {
      return
    }
    if (audiencesResponse.error) {
      return
    }
    setSendAsOptions(sendAsOptionsResponse.data)
    setAudiences(audiencesResponse.data)
  }
  
  const getSendAsOptions = async () => {
    return await api.call({
      query: {
        methodname: 'local_announcements2-get_sendas_options'
      }
    })
  }

  const getAudiences = async () => {
    return await api.call({
      query: {
        methodname: 'local_announcements2-get_audiences'
      }
    })
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setErrors({} as Errors)

    // Serialize page builder rows to JSON string for storage in message field
    const serializedRows = JSON.stringify(pageBuilderRows);
    const updatedData = { ...data, message: serializedRows };

    console.log(updatedData)
    return

    let formData = JSON.parse(JSON.stringify(updatedData))


    const response = await api.call({
      method: "POST",
      body: {
        methodname: 'local_announcements2-post_announcement',
        args: formData,
      }
    })

    if (response.error) {
      setErrors({submit: response.exception?.message ?? "Error submitting form"})
      return
    }

    // On success, redirect to new ID if created.
    navigate('/' + response.data.id, {replace: true})
  }


  const updateField = (field: keyof Announcement, value: any) => {
    setData((state: Announcement) => ({
      ...state,
      [field]: value
    }))
  }

  const updateAttachments = (value: string) => {
    updateField('attachments', value)
  }

  // Only staff should be able to post/edit announcements
  if (!getConfig().roles?.includes("staff")) {
    return ""
  }

  return (
    <>
      <Header />
      <div style={{minHeight: 'calc(100vh - 154px)'}}>

        { errors.submit && errors.submit.length > 0 
          ? <Container size="xl">
              <Center h={300}>
                <Text fw={600} fz="lg">Failed to post announcement...</Text>
                <Text c="red" mt="md">{errors.submit}</Text>
              </Center>
            </Container> 
          : <>
              <Container size="xl">
                <div className="page-header">
                  <Container size="xl" my="md" className="relative p-0 ps-7">
                      <Breadcrumbs fz="sm" mb="sm">
                        <RouterLink to="/">
                          <Text c="blue">Announcements</Text>
                        </RouterLink>
                        <Text c="gray.6">New</Text>
                      </Breadcrumbs>
                  </Container>
                </div>
              </Container>
              <Container size="xl" my="md">
                <form noValidate onSubmit={handleSubmit}>

                <Grid grow>
                <Grid.Col span={{ base: 12, lg: 12 }}>
                  <Box className="flex flex-col gap-4 relative ps-7 pe-7">

                
                    <div>
                      <Text fz="md" fw={500} c="#212529" mb="xs">Subject</Text>
                      <TextInput
                        value={data.subject}
                        onChange={(e) => setData({...data, subject: e.target.value})}
                      />
                    </div>

                    <div>
                      <Group justify="space-between" className="mb-1">
                        <Text fz="md" fw={500} c="#212529">Compose message</Text>
                        <Button variant="light" size="compact-sm"  leftSection={<IconEye size={16} />} onClick={() => setPreview(true)}>
                          Preview
                        </Button>
                      </Group>

                      <div className="p-6 pr-10 bg-gray-50 border rounded-sm">
                        <div className="max-w-[760px] mx-auto">
                          <PageBuilder 
                            rows={pageBuilderRows} 
                            onChange={setPageBuilderRows}
                          />
                        </div>
                      </div>
                    </div>

                    <div>
                      <Text fz="md" fw={500} c="#212529" mb="xs">Attachments</Text>
                      <div className="border">
                        <FileUploader 
                          desc={`Drag and drop files here...`} 
                          maxFiles={5} 
                          maxSize={10} 
                          existingfiles={[]} 
                          setState={updateAttachments} 
                        />
                      </div>
                    </div>


                    <div>
                      <Text fz="md" fw={500} c="#212529" mb="xs">Audiences</Text>
                      <div>
                        {api.state.loading ? (
                          <Text fz="sm" c="dimmed">Loading audiences...</Text>
                        ) : Object.keys(audiences).length > 0 ? (
                          <AudienceSelector
                            options={audiences}
                              onChange={(value) => setData({...data, audiences: value})}
                              value={data.audiences}
                            />
                        ) : null}
                      </div>
                    </div>

                



                  </Box>
                  </Grid.Col>
                  <Grid.Col span={{ base: 12, lg: 12 }}>

                    <div className="ps-7 pe-7">

                      <Text fz="md" fw={500} c="#212529" className="mb-2">Display settings</Text>

                      <div className="flex flex-col gap-4 bg-white p-4 rounded-md border">


                        { sendAsOptions.length > 0 && ( 
                            <UserCombobox
                              value={data.impersonate && data.impersonate.length > 0 ? data.impersonate[0] : null}
                              onChange={(value) => setData({...data, impersonate: value ? [value] : []})}
                              options={sendAsOptions}
                              label="Send as"
                              description="Send as another user or leave blank to send as yourself"
                              placeholder="Select user..."
                            />
                        )}



                        <div>
                          <Text fz="sm" mb="5px" fw={500} c="#212529">Display start</Text>
                          <DateTimePicker
                            value={dayjs.unix(Number(data.timestart))}
                            onChange={(newValue) => updateField('timestart', (newValue?.unix() ?? 0).toString())}
                            views={['day', 'month', 'year', 'hours', 'minutes']}
                            slotProps={{
                              textField: {
                                error: !!errors.timestart,
                              },
                            }}
                          />
                        </div>


                        <div>
                          <Text fz="sm" mb="5px" fw={500} c="#212529">Display end</Text>
                          <Checkbox
                            label="Enable"
                            checked={data.endenabled}
                            onChange={(e) => setData({...data, endenabled: e.target.checked})}
                            className="mb-1"
                          />
                          {data.endenabled && (
                          <DateTimePicker 
                            value={dayjs.unix(Number(data.timeend))}
                            onChange={(newValue) => {
                              updateField('timeend', (newValue?.unix() ?? 0).toString());
                            }}
                            views={['day', 'month', 'year', 'hours', 'minutes']}
                            slotProps={{
                              textField: {
                                error: !!errors.timeend,
                                },
                              }}
                            />
                          )}
                        </div>

                        
                        <div>
                          <Text fz="sm" fw={500} c="#212529" className="mb-1">Send immediately</Text>
                          <Checkbox
                            label="Send a copy of this announcement immediately. This should only be used for emergencies."
                            checked={data.forcesend}
                            onChange={(e) => setData({...data, forcesend: e.target.checked})}
                            c="red"
                          />
                        </div>


                        


                      </div>


                      <div className="flex gap-2 mt-4">
                        <Button 
                          type="submit"
                          variant="primary"
                          size="compact-md"
                          leftSection={<IconSend size={14} />}
                        >
                          Post
                        </Button>
                      </div>


                    </div>

                   
                    
                  </Grid.Col>
                  
                  </Grid>
                </form>
              </Container>
            </> 
        }

      </div>
      <Footer />

      {preview && (
        <Modal opened={preview} onClose={() => setPreview(false)} title="Preview" size="760px">
          <Text fz="lg" fw={500} c="#212529">{data.subject}</Text>
          <PagePreview rows={pageBuilderRows} />
        </Modal>
      )}
    </>
  );
}